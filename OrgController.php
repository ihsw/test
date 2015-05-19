<?php

namespace Leapshot\IoBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller,
	Symfony\Component\HttpFoundation\JsonResponse,
	Symfony\Component\Form\Form;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter,
	Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;

use Leapshot\IoBundle\Cache\ExternalApp as ExternalAppCache,
	Leapshot\IoBundle\Location\Organization as OrgLocation;

class OrganizationController extends Controller
{
	/**
	 * @Security("has_role('ROLE_USER')")
	 */
	public function createAction()
	{
		// services
		$orgFormHelper = $this->get("io.orgformhelper");
		$request = $this->get("request");

		// running over the form
		list($org, $form,) = $orgFormHelper->createOrganization(function(Form $form) use($request){
			$form->handleRequest($request);
			return $form;
		});
		if (!$form->isValid())
		{
			return $this->render("LeapshotIoBundle:Organization:create.html.twig", ["form" => $form->createView()]);
		}

		// redirecting
		return $this->redirect($this->generateUrl("orgEdit", ["orgSlug" => $org->getSlug()]));
	}

	/**
	 * @ParamConverter("org-from-slug")
	 */
	public function viewAction($org)
	{
		// services
		$userHelper = $this->get("io.userhelper");
		$doctrine = $this->get("doctrine");
		$orgHelper = $this->get("io.orghelper");
		$twigExtension = $this->get("io.twigextension");
		$redisHelper = $this->get("io.redishelper");

		return $this->render("LeapshotIoBundle:Organization:view.html.twig", $orgHelper->getViewData(
			$org,
			$userHelper,
			$doctrine,
			$twigExtension,
			$redisHelper
		));
	}

	/**
	 * @Security("has_role('ROLE_USER')")
	 * @ParamConverter("org-owned-or-admin-from-slug")
	 */
	public function editAction($org)
	{
		// services
		$doctrine = $this->get("doctrine");

		// locations
		$orgLocation = new OrgLocation($doctrine->getManager("location")->getConnection());

		// resolving the google-simple-api-key
		$params = $this->container->getParameter("io_params");
		$googleSimpleApiKey = null;
		if (array_key_exists("google", $params) && array_key_exists("simple_api_key", $params["google"]))
		{
			$googleSimpleApiKey = $params["google"]["simple_api_key"];
		}

		// resolving this org's location
		$location = $orgLocation->getLocation($org);

		return $this->render("LeapshotIoBundle:Organization:edit.html.twig", [
			"org" => $org,
			"encodedOrg" => is_null($location) ? $org->jsonSerialize() : array_merge($org->jsonSerialize(), $location),
			"googleSimpleApiKey" => $googleSimpleApiKey
		]);
	}

	/**
	 * @Security("has_role('ROLE_USER')")
	 * @ParamConverter("org-owned-or-admin-from-slug")
	 */
	public function updateAction($org)
	{
		$orgFormHelper = $this->get("io.orgformhelper");
		$request = $this->get("request");

		// running over the form
		list($org, $errors) = $orgFormHelper->updateOrganization($org, function(Form $form) use($request){
			$content = json_decode($request->getContent(), true);
			$form->bind($content);
			return $form;
		});
		if (!is_null($errors))
		{
			return new JsonResponse(["errors" => $errors]);
		}

		return new JsonResponse(["destination" => $this->generateUrl("orgEdit", ["orgSlug" => $org->getSlug()])]);
	}

	/**
	 * @Security("has_role('ROLE_USER')")
	 * @ParamConverter("org-owned-or-admin-from-slug")
	 */
	public function orgYoutubeAction($org)
	{
		// services
		$orgFormHelper = $this->get("io.orgformhelper");
		$request = $this->get("request");
		$session = $this->get("session");

		// running over the form
		list($org, $form, $errors) = $orgFormHelper->setOrgYoutube($org, function(Form $form) use($request){
			$form->handleRequest($request);
			return $form;
		});
		if (!$form->isValid())
		{
			return $this->render("LeapshotIoBundle:Organization:org-youtube.html.twig", [
				"form" => $form->createView(),
				"org" => $org
			]);
		}

		// showing a success message
		$session->getFlashBag()->add("success", "Your organization youtube panel has been updated!");
		return $this->redirect($this->generateUrl("orgOrgYoutube", ["orgSlug" => $org->getSlug()]));
	}

	/**
	 * @Security("has_role('ROLE_USER')")
	 * @ParamConverter("org-owned-or-admin-from-slug")
	 */
	public function orgYoutubeRemoveAction($org)
	{
		// services
		$orgFormHelper = $this->get("io.orgformhelper");
		$session = $this->get("session");

		$orgYoutube = $org->getOrgYoutube();
		if (!is_null($orgYoutube))
		{
			$org = $orgFormHelper->deleteOrgYoutube($org);
			$session->getFlashBag()->add("success", "Your organization youtube panel has been removed!");
		}

		// showing a success message
		return $this->redirect($this->generateUrl("orgOrgYoutube", ["orgSlug" => $org->getSlug()]));
	}

	/**
	 * @Security("has_role('ROLE_USER')")
	 * @ParamConverter("org-owned-or-admin-from-slug")
	 */
	public function orgVimeoAction($org)
	{
		// services
		$orgFormHelper = $this->get("io.orgformhelper");
		$request = $this->get("request");
		$session = $this->get("session");

		// running over the form
		list($org, $form, $errors) = $orgFormHelper->setOrgVimeo($org, function(Form $form) use($request){
			$form->handleRequest($request);
			return $form;
		});
		if (!$form->isValid())
		{
			return $this->render("LeapshotIoBundle:Organization:org-vimeo.html.twig", [
				"form" => $form->createView(),
				"org" => $org
			]);
		}

		// showing a success message
		$session->getFlashBag()->add("success", "Your organization vimeo panel has been updated!");
		return $this->redirect($this->generateUrl("orgOrgVimeo", ["orgSlug" => $org->getSlug()]));
	}

	/**
	 * @Security("has_role('ROLE_USER')")
	 * @ParamConverter("org-owned-or-admin-from-slug")
	 */
	public function orgVimeoRemoveAction($org)
	{
		// services
		$orgFormHelper = $this->get("io.orgformhelper");
		$session = $this->get("session");

		$orgVimeo = $org->getOrgVimeo();
		if (!is_null($orgVimeo))
		{
			$org = $orgFormHelper->deleteOrgVimeo($org);
			$session->getFlashBag()->add("success", "Your organization vimeo panel has been removed!");
		}

		// showing a success message
		return $this->redirect($this->generateUrl("orgOrgVimeo", ["orgSlug" => $org->getSlug()]));
	}

	/**
	 * @ParamConverter("org-owned-or-admin-from-slug")
	 */
	public function statsAction($org)
	{
		// services
		$redisHelper = $this->get("io.redishelper");
		$doctrine = $this->get("doctrine");

		// repositories
		$linkRepo = $doctrine->getRepository("LeapshotIoBundle:ExternalApp");

		// caches
		$externalAppCache = new ExternalAppCache($redisHelper->getRedis());

		// gathering click rates for this org, and resolving the links associated
		$overallLinkClicks = $externalAppCache->getTopOrgClicks($org);
		$generalLinkClicks = $externalAppCache->getGeneralClicks(array_keys($overallLinkClicks));
		$links = $linkRepo->findById(array_keys($overallLinkClicks));

		return $this->render("LeapshotIoBundle:Organization:stats.html.twig", [
			"org" => $org,
			"links" => $links,
			"overallLinkClicks" => $overallLinkClicks,
			"generalLinkClicks" => $generalLinkClicks
		]);
	}
}
