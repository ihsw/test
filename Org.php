<?php

namespace Leapshot\IoBundle\Helper\Form;

use Doctrine\Bundle\DoctrineBundle\Registry;

use Leapshot\IoBundle\Helper\Form\AbstractForm,
	Leapshot\IoBundle\Helper\User as UserHelper,
	Leapshot\IoBundle\Helper\File as FileHelper,
	Leapshot\IoBundle\Helper\Org as OrgHelper,
	Leapshot\IoBundle\Helper\Redis as RedisHelper,
	Leapshot\IoBundle\Cache\ExternalApp as ExternalAppCache,
	Leapshot\IoBundle\Entity\Organization as OrgEntity,
	Leapshot\IoBundle\Entity\OrgYoutube,
	Leapshot\IoBundle\Entity\OrgVimeo,
	Leapshot\IoBundle\Form\Type\Org\CreateType as OrgCreateType,
	Leapshot\IoBundle\Form\Type\Org\ProfileType as OrgProfileType,
	Leapshot\IoBundle\Form\Type\OrgYoutubeType,
	Leapshot\IoBundle\Form\Type\OrgVimeoType,
	Leapshot\IoBundle\Location\Organization as OrgLocation,
	Leapshot\IoBundle\Location\ExternalApp as ExternalAppLocation;

class Organization extends AbstractForm
{
	private $doctrine;
	private $userHelper;
	private $fileHelper;
	private $orgHelper;
	private $redisHelper;

	public function __construct(Registry $doctrine, UserHelper $userHelper, FileHelper $fileHelper, OrgHelper $orgHelper, RedisHelper $redisHelper)
	{
		$this->doctrine = $doctrine;
		$this->userHelper = $userHelper;
		$this->fileHelper = $fileHelper;
		$this->orgHelper = $orgHelper;
		$this->redisHelper = $redisHelper;
	}

	/**
	 * @param \Closure $bindForm func(Form) Form
	 * @param bool $errorBubbling
	 * @return array OrgEntity|null, Form, []string|null
	 */
	public function createOrganization(\Closure $bindForm, $errorBubbling = false)
	{
		// drawing up a new org
		$org = new OrgEntity();
		$org->setPrivacyLevel(OrgEntity::PRIVATE_ORG);

		// running over the form
		list($form, $errors) = $this->bindAndRunValidation(new OrgCreateType($errorBubbling), $bindForm, $org);
		if (!is_null($errors))
		{
			return [null, $form, $this->getErrors($form)];
		}

		// saving the org
		$em = $this->doctrine->getManager();
		$user = $this->userHelper->getUser();
		$org->setSlug($this->orgHelper->slugify($org->getName()))
			->setOwnerUser($user)
			->addUserMember($user)
			->addUserAdmin($user);
		$em->persist($org);
		$em->flush();

		return [$org, $form, null];
	}

	/**
	 * @param OrgEntity
	 */
	public function deleteOrganization(OrgEntity $org)
	{
		// misc
		$doctrine = $this->doctrine;
		$em = $doctrine->getManager();
		$fileHandler = $this->fileHelper->getHandler();

		// locations
		$locationConn = $doctrine->getManager("location")->getConnection();
		$orgLocation = new OrgLocation($locationConn);
		$externalAppLocation = new ExternalAppLocation($locationConn);

		// caches
		$externalAppCache = new ExternalAppCache($this->redisHelper->getRedis());

		// deleting many-to-many relationships
		if (count($org->getUserMembers()) > 0)
		{
			foreach ($org->getUserMembers() as $user)
			{
				$org->removeUserMember($user);
			}
		}
		if (count($org->getUserAdmins()) > 0)
		{
			foreach ($org->getUserAdmins() as $user)
			{
				$org->removeUserAdmin($user);
			}
		}

		// removing one-to-many child entities
		if (count($org->getInvites()) > 0)
		{
			foreach ($org->getInvites() as $invite)
			{
				$em->remove($invite);
			}
		}
		if (count($org->getMembershipRequests()) > 0)
		{
			foreach ($org->getMembershipRequests() as $membershipRequest)
			{
				$em->remove($membershipRequest);
			}
		}
		if (count($org->getLastViewedBy()) > 0)
		{
			foreach ($org->getLastViewedBy() as $user)
			{
				$user->setLastViewedOrg(null);
			}
		}
		if (count($org->getNews()) > 0)
		{
			foreach ($org->getNews() as $news)
			{
				$em->remove($news);
			}
		}
		if (count($org->getExternalApps()) > 0)
		{
			foreach ($org->getExternalApps() as $externalApp)
			{
				if ($fileHandler->externalAppIconExists($externalApp))
				{
					$fileHandler->deleteExternalAppIcon($externalApp);
				}
				$externalApp->setIcon(null);

				$externalAppCache->removeClicks($externalApp);
				$externalAppLocation->delete($externalApp);

				$em->remove($externalApp);
			}
		}
		if (count($org->getExternalAppFolders()) > 0)
		{
			foreach ($org->getExternalAppFolders() as $folder)
			{
				$em->remove($folder);
			}
		}
		if (count($org->getMultilinks()) > 0)
		{
			foreach ($org->getMultilinks() as $multilink)
			{
				$em->remove($multilink);
			}
		}
		if (count($org->getFundraisers()) > 0)
		{
			foreach ($org->getFundraisers() as $fundraiser)
			{
				if (count($fundraiser->getCommits()) > 0)
				{
					foreach ($fundraiser->getCommits() as $commit)
					{
						if (!is_null($commit->getUserCommit()))
						{
							$em->remove($commit->getUserCommit());
						}
						if (!is_null($commit->getAnonymousCommit()))
						{
							$em->remove($commit->getAnonymousCommit());
						}

						$em->remove($commit);
					}
				}

				$em->remove($fundraiser);
			}
		}

		// removing one-to-one relations
		if (!is_null($org->getOrgVimeo()))
		{
			$em->remove($org->getOrgVimeo());
		}
		if (!is_null($org->getOrgYoutube()))
		{
			$em->remove($org->getOrgYoutube());
		}

		// removing the logo
		if ($fileHandler->orgLogoExists($org))
		{
			$fileHandler->deleteOrgLogo($org);
		}
		$org->setLogo(null);

		// removing the location information
		$orgLocation = new OrgLocation($doctrine->getManager("location")->getConnection());
		$orgLocation->delete($org);

		// removing the entity itself
		$em->flush();
		$em->remove($org);
		$em->flush();
	}

	/**
	 * @param OrgEntity $org
	 * @param \Closure $bindForm func(Form) Form
	 * @param bool $errorBubbling
	 * @return array OrgEntity|null, []string|null
	 */
	public function updateOrganization(OrgEntity $org, \Closure $bindForm, $errorBubbling = true)
	{
		// misc
		$orgHelper = $this->orgHelper;
		$doctrine = $this->doctrine;

		// locations
		$orgLocation = new OrgLocation($doctrine->getManager("location")->getConnection());

		// running over the form
		list($form, $errors) = $this->bindAndRunValidation(new OrgProfileType($org, $orgHelper->getPrivacyLevels(), $errorBubbling), $bindForm, $org);
		if (!is_null($errors))
		{
			return [null, $errors];
		}

		// saving the org
		$em = $doctrine->getManager();
		$org->setSlug($orgHelper->slugify($org->getName()));
		$em->persist($org);
		$em->flush();

		// optionally handling the lat/long
		$latitude = $form["latitude"]->getData();
		$longitude = $form["longitude"]->getData();
		$address = $form["address"]->getData();
		if (!is_null($latitude) && !is_null($longitude) && !is_null($address))
		{
			$orgLocation->track($org, $latitude, $longitude);
		}
		else
		{
			$orgLocation->delete($org);
		}

		return [$org, null];
	}

	/**
	 * @param OrgEntity $org
	 * @param \Closure $bindForm func(Form) Form
	 * @param bool $errorBubbling
	 * @return array OrgEntity|null, Form, []string|null
	 */
	public function setOrgYoutube(OrgEntity $org, \Closure $bindForm, $errorBubbling = false)
	{
		$orgYoutube = $org->getOrgYoutube();
		if (is_null($orgYoutube))
		{
			$orgYoutube = new OrgYoutube();
			$orgYoutube->setOrganization($org);
		}
		list($form, $errors) = $this->bindAndRunValidation(new OrgYoutubeType($errorBubbling), $bindForm, $orgYoutube);
		if (!is_null($errors))
		{
			return [$org, $form, $errors];
		}

		$em = $this->doctrine->getManager();
		$em->persist($orgYoutube);
		$em->flush();

		$org->setOrgYoutube($orgYoutube);

		return [$org, $form, null];
	}

	/**
	 * @param OrgEntity
	 * @return OrgEntity
	 */
	public function deleteOrgYoutube(OrgEntity $org)
	{
		$em = $this->doctrine->getManager();
		$em->remove($org->getOrgYoutube());
		$org->setOrgYoutube(null);
		$em->flush();

		return $org;
	}

	/**
	 * @param OrgEntity $org
	 * @param \Closure $bindForm func(Form) Form
	 * @param bool $errorBubbling
	 * @return array OrgEntity, Form, []string|null
	 */
	public function setOrgVimeo(OrgEntity $org, \Closure $bindForm, $errorBubbling = false)
	{
		$orgVimeo = $org->getOrgVimeo();
		if (is_null($orgVimeo))
		{
			$orgVimeo = new OrgVimeo();
			$orgVimeo->setOrganization($org);
		}
		list($form, $errors) = $this->bindAndRunValidation(new OrgVimeoType($errorBubbling), $bindForm, $orgVimeo);
		if (!is_null($errors))
		{
			return [$org, $form, $errors];
		}

		$em = $this->doctrine->getManager();
		$em->persist($orgVimeo);
		$em->flush();

		$org->setOrgVimeo($orgVimeo);

		return [$org, $form, null];
	}

	/**
	 * @param OrgEntity
	 * @return OrgEntity
	 */
	public function deleteOrgVimeo(OrgEntity $org)
	{
		$em = $this->doctrine->getManager();
		$em->remove($org->getOrgVimeo());
		$org->setOrgVimeo(null);
		$em->flush();

		return $org;
	}
}