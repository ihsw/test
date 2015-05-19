<?php namespace Leapshot\IoBundle\Helper\Form;

use Doctrine\Bundle\DoctrineBundle\Registry;
use Guzzle\Http\Client,
    Guzzle\Http\Message\Response,
    Guzzle\Http\Exception\RequestException,
    Guzzle\Http\Exception\ClientErrorResponseException,
    Guzzle\Http\Exception\ServerErrorResponseException,
    Guzzle\Http\Exception\CurlException;
use Imagine\Imagick\Imagine,
    Imagine\Imagick\Image,
    Imagine\Image\Box,
    Imagine\Image\ImageInterface;
use Symfony\Component\Process\Process,
    Symfony\Component\Process\Exception\ProcessTimedOutException;
use Leapshot\IoBundle\Helper\Form\AbstractForm,
    Leapshot\IoBundle\Cache\Screenshot as ScreenshotCache,
    Leapshot\IoBundle\Entity\Screenshot as ScreenshotEntity,
    Leapshot\IoBundle\Entity\User as UserEntity,
    Leapshot\IoBundle\Form\Type\ScreenshotType,
    Leapshot\IoBundle\Logger\HeadLogger,
    Leapshot\IoBundle\Helper\File\Local as LocalFileHelper,
    Leapshot\IoBundle\Helper\File as FileHelper,
    Leapshot\IoBundle\Helper\Redis as RedisHelper;

class Screenshot extends AbstractForm
{
    private $doctrine;
    private $localFileHelper;
    private $fileHelper;
    private $redisHelper;
    private $headLogger;
    private $kernel;

    public function __construct(Registry $doctrine, LocalFileHelper $localFileHelper, FileHelper $fileHelper,
        RedisHelper $redisHelper, HeadLogger $headLogger, \AppKernel $kernel)
    {
        $this->doctrine = $doctrine;
        $this->localFileHelper = $localFileHelper;
        $this->fileHelper = $fileHelper;
        $this->redisHelper = $redisHelper;
        $this->headLogger = $headLogger;
        $this->kernel = $kernel;
    }

    /**
     * @param UserEntity $user
     * @param \Closure $bindForm func(Form) Form
     * @return array ScreenshotEntity|null, []string|null
     */
    public function create(UserEntity $user, \Closure $bindForm)
    {
        // starting up a blank screenshot
        $screenshot = new ScreenshotEntity();
        $screenshot->setStatus(ScreenshotEntity::CREATED)
            ->setUser($user);

        // running over the form
        list($form, $errors) = $this->bindAndRunValidation(new ScreenshotType(true), $bindForm, $screenshot);
        if (!is_null($errors)) {
            return [null, $errors];
        }

        $em = $this->doctrine->getManager();
        $em->persist($screenshot);
        $em->flush();

        $screenshotCache = new ScreenshotCache($this->redisHelper->getRedis());
        $screenshotCache->queueScreenshotToDownload($screenshot);

        return [$screenshot, null];
    }

    /**
     * @param ScreenshotEntity
     */
    public function remove(ScreenshotEntity $screenshot)
    {
        $em = $this->doctrine->getManager();
        $em->remove($screenshot);
        $em->flush();
    }

    /**
     * @param ScreenshotEntity
     * @return array ScreenshotEntity, Response|null, \Exception|null
     */
    public function fetchHead(ScreenshotEntity $screenshot)
    {
        // caches
        $redis = $this->redisHelper->getRedis();
        $screenshotCache = new ScreenshotCache($redis);

        // misc
        $em = $this->doctrine->getManager();
        $fail = function($screenshot, $e, $reason) use($screenshotCache, $redis, $em) {
            // logging the exception class
            $redis->zIncrBy("process-exceptions", 1, get_class($e));

            // saving the screenshot
            $screenshot->setStatus(ScreenshotEntity::FAIL)
                ->setFailReason($reason)
                ->setFinishedAt(new \DateTime());
            $em->persist($screenshot);
            $em->flush();

            // logging the exception
            $this->headLogger->logException($e);

            // queueing the screenshot up for failure
            $screenshotCache->queueScreenshotForFailure($screenshot, $reason);

            return $screenshot;
        };

        $response = null;
        try {
            $client = new Client();
            $response = $client->head($screenshot->getDestination())->send();
        } catch (ClientErrorResponseException $e) {
            $responseStatusCode = $e->getResponse()->getStatusCode();
            $redis->zIncrBy("process-exception-codes", 1, $responseStatusCode);

            $badCodes = [401, 403, 404];
            $permissableCodes = [405, 406];
            if (in_array($responseStatusCode, $permissableCodes)) {
                return [$screenshot, null, null];
            } else if (in_array($responseStatusCode, $badCodes)) {
                $reason = sprintf("%s_%s", ScreenshotEntity::CLIENT_ERROR, $responseStatusCode);
                return [$fail($screenshot, $e, $reason), null, $e];
            } else {
                return [$fail($screenshot, $e, ScreenshotEntity::CLIENT_ERROR_OTHER), null, $e];
            }
        } catch (ServerErrorResponseException $e) {
            $responseStatusCode = $e->getResponse()->getStatusCode();
            $redis->zIncrBy("process-exception-codes", 1, $responseStatusCode);

            $reason = ScreenshotEntity::SERVER_ERROR_OTHER;
            $badCodes = [500, 503];
            if (in_array($responseStatusCode, $badCodes)) {
                $reason = sprintf("%s_%s", ScreenshotEntity::SERVER_ERROR, $responseStatusCode);
            }

            return [$fail($screenshot, $e, $reason), null, $e];
        } catch (CurlException $e) {
            $curlErrorNumber = $e->getErrorNo();
            $redis->zIncrBy("process-exception-curl-error-numbers", 1, $curlErrorNumber);

            $reason = ScreenshotEntity::CURL_ERROR_OTHER;
            $sslFailCodes = [51, 35, 60];
            $networkFailCodes = [56, 7];
            $invalidHostCode = 6;
            $emptyResponseCode = 52;
            if (in_array($curlErrorNumber, $sslFailCodes)) {
                $reason = ScreenshotEntity::CURL_ERROR_SSL;
            }
            else if (in_array($curlErrorNumber, $networkFailCodes)) {
                $reason = ScreenshotEntity::CURL_ERROR_NETWORK_FAILURE;
            }
            else if ($curlErrorNumber === $invalidHostCode) {
                $reason = ScreenshotEntity::CURL_ERROR_INVALID_HOST;
            }
            else if ($curlErrorNumber === $emptyResponseCode) {
                $reason = ScreenshotEntity::CURL_ERROR_EMPTY_RESPONSE;
            }

            return [$fail($screenshot, $e, $reason), null, $e];
        } catch (RequestException $e) {
            return [$fail($screenshot, $e, ScreenshotEntity::OTHER), null, $e];
        }

        return [$screenshot, $response, null];
    }

    /**
     * @param ScreenshotEntity $screenshot
     * @return Image
     */
    public function fetchImageViaGet(ScreenshotEntity $screenshot)
    {
        $imagine = new Imagine();
        $client = new Client();
        $request = $client->get($screenshot->getDestination());
        return $imagine->load($request->send()->getBody());
    }

    /**
     * @param ScreenshotEntity
     * @param \Closure func(string) Process
     * @return array Image|null, \Exception|null
     */
    private function fetchImageViaProcess(ScreenshotEntity $screenshot, \Closure $getProcess)
    {
        // misc
        $localFileHelper = $this->localFileHelper;

        // checking the destination folder and creating it where appropriate
        $destinationFolder = $localFileHelper->getScreenshotFolder($screenshot);
        if (!file_exists($destinationFolder)) {
            mkdir($destinationFolder, 0755, true);
        }

        // drafting a process and running it
        $fileDestination = $localFileHelper->getScreenshotImagePath($screenshot);
        $process = $getProcess($fileDestination);
        $process->setTimeout(60);
        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            return [null, $e];
        }

        // checking for errors
        if ($process->isSuccessful() && !$localFileHelper->exists($fileDestination)) {
            return [
                null,
                new \RuntimeException(
                    sprintf("Process succeeded but dumped an empty file: %s", $process->getCommandLine())
                )
            ];
        } else if (!$process->isSuccessful() || !$localFileHelper->exists($fileDestination)) {
            return [null, new \RuntimeException($process->getErrorOutput())];
        }

        // loading the image and removing the temporary file
        $imagine = new Imagine();
        $image = $imagine->load($localFileHelper->read($fileDestination));
        $localFileHelper->deleteScreenshotImage($screenshot);
        return [$image, null];
    }

    /**
     * @param ScreenshotEntity $screenshot
     * @return Image|null, \Exception|null
     */
    public function fetchImageViaWkhtml(ScreenshotEntity $screenshot)
    {
        return $this->fetchImageViaProcess($screenshot, function($fileDestination) use($screenshot) {
            return new Process(sprintf(
                "wkhtmltoimage --crop-h 1000 -f jpeg %s %s",
                escapeshellarg($screenshot->getDestination()),
                $fileDestination
            ));
        });
    }

    /**
     * @param ScreenshotEntity $screenshot
     * @return Image|null, \Exception|null
     */
    public function fetchImageViaPhantomJs(ScreenshotEntity $screenshot)
    {
        return $this->fetchImageViaProcess($screenshot, function($fileDestination) use($screenshot) {
            $scriptPath = sprintf("%s/Helper/render.js", $this->kernel->getBundle("LeapshotIoBundle")->getPath());
            return new Process(sprintf(
                "phantomjs %s %s %s",
                $scriptPath,
                escapeshellarg($screenshot->getDestination()),
                $fileDestination
            ));
        });
    }

    /**
     * @param ScreenshotEntity
     * @return array ScreenshotEntity, \Exception|null
     */
    public function fetch(ScreenshotEntity $screenshot)
    {
        // caches
        $redis = $this->redisHelper->getRedis();
        $screenshotCache = new ScreenshotCache($redis);

        // misc
        $em = $this->doctrine->getManager();

        // flagging the screenshot as having been started
        $screenshot->setStatus(ScreenshotEntity::START)
            ->setStartedAt(new \DateTime());
        $em->persist($screenshot);
        $em->flush();

        // validating via HEAD request
        list($screenshot, $response, $e) = $this->fetchHead($screenshot);
        if (!is_null($e)) {
            return [$screenshot, $e];
        }

        // misc
        $contentType = is_null($response) ? null : $response->getHeader("Content-type");
        $imageMimetypes = ["image/png", "image/jpeg"];
        $isImage = !is_null($contentType) && in_array($contentType, $imageMimetypes);

        // optionally declining to download destinations that are too large
        if (!is_null($response) && !is_null($response->getHeader("Content-length"))
            && $response->getHeader("Content-length")->__toString() > 4*1000*1000) {
            $screenshot->setStatus(ScreenshotEntity::FAIL)
                ->setFailReason(ScreenshotEntity::FILESIZE_TOO_BIG)
                ->setFinishedAt(new \DateTime());
            $em->persist($screenshot);
            $em->flush();

            $screenshotCache->queueScreenshotForFailure($screenshot, ScreenshotEntity::FILESIZE_TOO_BIG);

            return [$screenshot, new \RuntimeException("Destination filesize was too big")];
        }

        // fetching the image via GET, wkhtml, or phantomjs
        if ($isImage) {
            return [$this->fetchImageViaGet($screenshot), null];
        }
        list($image, ) = $this->fetchImageViaWkhtml($screenshot);
        if (!is_null($image)) {
            return [$image, null];
        }
        list($image, $e) = $this->fetchImageViaPhantomJs($screenshot);
        if (!is_null($image)) {
            return [$image, null];
        }

        // flagging it as having failed via phantomjs
        $screenshot->setStatus(ScreenshotEntity::FAIL)
            ->setFailReason(ScreenshotEntity::PROCESS_ERROR_PHANTOMJS)
            ->setFinishedAt(new \DateTime());
        $em->persist($screenshot);
        $em->flush();
        $screenshotCache->queueScreenshotForFailure($screenshot, ScreenshotEntity::PROCESS_ERROR_PHANTOMJS);

        return [null, $e];
    }
}