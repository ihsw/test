<?php

namespace Leapshot\IoBundle\Controller\Organization;

use Symfony\Bundle\FrameworkBundle\Controller\Controller,
	Symfony\Component\HttpFoundation\JsonResponse,
	Symfony\Component\Form\Form;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter,
	Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;

/**
 * @Security("has_role('ROLE_USER')")
 */
class MembershipController extends Controller
{
	/**
	 * @ParamConverter("org-owned-or-admin-from-slug")
	 */
	public function indexAction($org)
	{
		// services
		$request = $this->get("request");
		$doctrine = $this->get("doctrine");
		$userHelper = $this->get("io.userhelper");
		$mailer = $this->get("mailer");
		$session = $this->get("session");
		$twigExtension = $this->get("io.twigextension");

		$orgMembershipFormHelper = $this->get("io.orgmembershipformhelper");

		// repositories
		$em = $doctrine->getManager();
		$userRepository = $em->getRepository("LeapshotIoBundle:User");
		$inviteRepository = $em->getRepository("LeapshotIoBundle:Invite");
		$membershipRequestRepository = $em->getRepository("LeapshotIoBundle:MembershipRequest");
		$orgRepository = $em->getRepository("LeapshotIoBundle:Organization");

		// misc
		$user = $userHelper->getUser();

		// running over the form
		list($invite, $form, $errors) = $orgMembershipFormHelper->invite($org, function(Form $form) use($request){
			$form->handleRequest($request);
			return $form;
		});
		if ($form->isValid())
		{
			$recipient = $invite->getRecipientEmail();
			if (!is_null($invite->getRecipientUser()))
			{
				$recipient = $invite->getRecipientUser()->getFullName();
			}

			$session->getFlashBag()->add("success", sprintf("%s was successfully invited to your organization!", $recipient));
			return $this->redirect($this->generateUrl("orgMembershipIndex", ["orgSlug" => $org->getSlug()]));
		}

		$pendingInvites = array_map(function($invite) use($twigExtension){
			$recipientUserIcon = null;
			if (!is_null($invite->getRecipientUser()))
			{
				$recipientUserIcon = $twigExtension->userImageUrl($invite->getRecipientUser());
			}

			return [
				"invite" => $invite->jsonSerialize(),
				"recipientUserIcon" => $recipientUserIcon,
				"resendUrl" => $this->generateUrl("orgMembershipResendInvite", [
					"orgSlug" => $invite->getOrganization()->getSlug(),
					"inviteId" => $invite->getId()
				]),
				"revokeUrl" => $this->generateUrl("orgMembershipRevokeInvite", [
					"orgSlug" => $invite->getOrganization()->getSlug(),
					"inviteId" => $invite->getId()
				])
			];
		}, $inviteRepository->getPendingOrgInvites($org));

		$pendingMembershipRequests = array_map(function($membershipRequest) use($twigExtension){
			$url = function ($route) use($membershipRequest){
				return $this->generateUrl($route, [
					"orgSlug" => $membershipRequest->getOrganization()->getSlug(),
					"membershipRequestId" => $membershipRequest->getId()
				]);
			};

			return [
				"membershipRequest" => $membershipRequest->jsonSerialize(),
				"senderUserIcon" => $twigExtension->userImageUrl($membershipRequest->getSenderUser()),
				"acceptUrl" => $url("orgMembershipAcceptRequest"),
				"rejectUrl" => $url("orgMembershipRejectRequest")
			];
		}, $membershipRequestRepository->getPendingOrgMembershipRequests($org));

		$members = array_map(function($member) use($twigExtension, $org, $user){
			$slugUrl = function ($route) use($member, $org){
				return $this->generateUrl($route, [
					"orgSlug" => $org->getSlug(),
					"userId" => $member->getId()
				]);
			};

			return [
				"member" => $member->jsonSerialize(),
				"memberIcon" => $twigExtension->userImageUrl($member),
				"showMenu" => $user->getId() !== $member->getId(),
				"memberOrgRank" => $twigExtension->orgRank($member, $org),
				"isOrgOwner" => $user->isOrgOwner($org),
				"isOrgOwnerOrAdmin" => $user->isOrgOwnerOrAdmin($org),
				"memberIsAdmin" => $member->isOrgAdmin($org),
				"demoteUrl" => $slugUrl("orgMembershipRemoveAdmin"),
				"promoteUrl" => $slugUrl("orgMembershipAddAdmin"),
				"transferOwnershipUrl" => $slugUrl("orgMembershipTransferOwnership"),
				"removeUrl" => $slugUrl("orgMembershipRemoveMember")
			];
		}, $org->getUserMembers()->toArray());

		return $this->render("LeapshotIoBundle:Organization/Membership:index.html.twig", [
			"form" => $form->createView(),
			"org" => $org,
			"pendingInvites" => $pendingInvites,
			"pendingMembershipRequests" => $pendingMembershipRequests,
			"members" => $members
		]);
	}

	/**
	 * @ParamConverter("org-owned-or-admin-from-slug/org-member")
	 */
	public function removeMemberAction($org, $user)
	{
		// services
		$orgMembershipFormHelper = $this->get("io.orgmembershipformhelper");
		$session = $this->get("session");

		list($org, $user) = $orgMembershipFormHelper->removeMember($org, $user);
		$session->getFlashBag()->add("success", sprintf("%s has been removed from your organization!", $user->getUsername()));
		return $this->redirect($this->generateUrl("orgMembershipIndex", ["orgSlug" => $org->getSlug()]));
	}

	/**
	 * @ParamConverter("org-owned-or-admin-from-slug/pending-org-invite")
	 */
	public function resendInviteAction($org, $invite)
	{
		// services
		$session = $this->get("session");
		$orgMembershipFormHelper = $this->get("io.orgmembershipformhelper");

		$recipient = $invite->getRecipientEmail();
		if (!is_null($invite->getRecipientUser()))
		{
			$recipient = $invite->getRecipientUser->getFullName();
		}

		$invite = $orgMembershipFormHelper->resendInvite($invite);
		$session->getFlashBag()->add("success", sprintf("Your invitation to %s has been resent!", $recipient));
		return $this->redirect($this->generateUrl("orgMembershipIndex", ["orgSlug" => $org->getSlug()]));
	}

	/**
	 * @ParamConverter("org-owned-or-admin-from-slug/pending-org-invite")
	 */
	public function revokeInviteAction($org, $invite)
	{
		// services
		$orgMembershipFormHelper = $this->get("io.orgmembershipformhelper");
		$session = $this->get("session");

		$recipient = $invite->getRecipientEmail();
		if (!is_null($invite->getRecipientUser()))
		{
			$recipient = $invite->getRecipientUser->getFullName();
		}
		$org = $orgMembershipFormHelper->revokeInvite($invite);

		$session->getFlashBag()->add("success", sprintf("Your invitation to %s has been revoked!", $recipient));
		return $this->redirect($this->generateUrl("orgMembershipIndex", ["orgSlug" => $org->getSlug()]));
	}

	/**
	 * @ParamConverter("org-owned-from-slug/org-member")
	 */
	public function addAdminAction($org, $user)
	{
		// services
		$orgMembershipFormHelper = $this->get("io.orgmembershipformhelper");
		$session = $this->get("session");

		list($org, $user) = $orgMembershipFormHelper->addAdmin($org, $user);
		$session->getFlashBag()->add("success", sprintf("%s has been promoted to an admin!", $user->getUsername()));
		return $this->redirect($this->generateUrl("orgMembershipIndex", ["orgSlug" => $org->getSlug()]));
	}

	/**
	 * @ParamConverter("org-owned-from-slug/org-admin")
	 */
	public function removeAdminAction($org, $user)
	{
		// services
		$orgMembershipFormHelper = $this->get("io.orgmembershipformhelper");
		$session = $this->get("session");

		list($org, $user) = $orgMembershipFormHelper->removeAdmin($org, $user);
		$session->getFlashBag()->add("success", sprintf("%s has been demoted to a member!", $user->getUsername()));
		return $this->redirect($this->generateUrl("orgMembershipIndex", ["orgSlug" => $org->getSlug()]));
	}

	/**
	 * @ParamConverter("org-owned-from-slug/org-member")
	 */
	public function transferOwnershipAction($org, $user)
	{
		// services
		$orgMembershipFormHelper = $this->get("io.orgmembershipformhelper");
		$session = $this->get("session");

		list($org, $user) = $orgMembershipFormHelper->transferOwnership($org, $user);
		$session->getFlashBag()->add("success", sprintf("Ownership of this portal has been transferred to %s!", $user->getUsername()));
		return $this->redirect($this->generateUrl("orgMembershipIndex", ["orgSlug" => $org->getSlug()]));
	}

	/**
	 * @ParamConverter("org-from-slug-semiprivate")
	 */
	public function requestAction($org)
	{
		// services
		$userHelper = $this->get("io.userhelper");

		// misc
		$user = $userHelper->getUser();

		// optionally halting where the user is already a member
		if ($user->isOrgMember($org))
		{
			$session->getFlashBag()->add("You are already a member of this organization and cannot request to join again!");
			return $this->redirect($this->generateUrl("orgView", ["orgSlug" => $org->getSlug()]));
		}

		return $this->render("LeapshotIoBundle:Organization/Membership:request.html.twig", ["org" => $org]);
	}

	/**
	 * @ParamConverter("org-from-slug-semiprivate")
	 */
	public function requestCheckAction($org)
	{
		// services
		$userHelper = $this->get("io.userhelper");
		$session = $this->get("session");
		$orgMembershipFormHelper = $this->get("io.orgmembershipformhelper");

		// misc
		$user = $userHelper->getUser();

		// optionally halting where the user is already a member
		if ($user->isOrgMember($org))
		{
			$session->getFlashBag()->add("You are already a member of this organization and cannot request to join again!");
			return $this->redirect($this->generateUrl("orgView", ["orgSlug" => $org->getSlug()]));
		}

		$membershipRequest = $orgMembershipFormHelper->requestMembership($org, $user);
		return $this->redirect($this->generateUrl("orgMembershipRequestSuccess", ["orgSlug" => $org->getSlug()]));
	}

	/**
	 * @ParamConverter("org-from-slug-semiprivate")
	 */
	public function requestSuccessAction($org)
	{
		return $this->render("LeapshotIoBundle:Organization/Membership:request-success.html.twig", ["org" => $org]);
	}

	/**
	 * @ParamConverter("org-owned-or-admin-from-slug/pending-org-membership-request")
	 */
	public function acceptRequestAction($org, $membershipRequest)
	{
		// services
		$session = $this->get("session");
		$orgMembershipFormHelper = $this->get("io.orgmembershipformhelper");

		// misc
		$senderUser = $membershipRequest->getSenderUser();

		// optionally halting where the sender-user is already a member
		if ($senderUser->isOrgMember($org))
		{
			$session->getFlashBag()->add("info", sprintf("%s is already a member of your organization and cannot be accepted again.", $senderUser->getUsername()));
			return $this->redirect($this->generateUrl("orgMembershipIndex", ["orgSlug" => $org->getSlug()]));
		}

		$orgMembershipFormHelper->acceptMembershipRequest($membershipRequest);
		$session->getFlashBag()->add("success", sprintf("%s has been accepted into your organization!", $senderUser->getUsername()));
		return $this->redirect($this->generateUrl("orgMembershipIndex", ["orgSlug" => $org->getSlug()]));
	}

	/**
	 * @ParamConverter("org-owned-or-admin-from-slug/pending-org-membership-request")
	 */
	public function rejectRequestAction($org, $membershipRequest)
	{
		// services
		$session = $this->get("session");
		$orgMembershipFormHelper = $this->get("io.orgmembershipformhelper");

		// misc
		$senderUser = $membershipRequest->getSenderUser();

		// optionally halting where the sender-user is already a member
		if ($senderUser->isOrgMember($org))
		{
			$session->getFlashBag()->add("info", sprintf("%s is already a member of your organization and their request cannot be denied.", $senderUser->getUsername()));
			return $this->redirect($this->generateUrl("orgMembershipIndex", ["orgSlug" => $org->getSlug()]));
		}

		$orgMembershipFormHelper->rejectMembershipRequest($membershipRequest);
		$session->getFlashBag()->add("success", sprintf("%s's membership request has been denied!", $senderUser->getUsername()));
		return $this->redirect($this->generateUrl("orgMembershipIndex", ["orgSlug" => $org->getSlug()]));
	}

	/**
	 * @ParamConverter("org-from-slug")
	 */
	public function joinAction($org)
	{
		// services
		$userHelper = $this->get("io.userhelper");
		$session = $this->get("session");
		$orgMembershipFormHelper = $this->get("io.orgmembershipformhelper");

		// optionally halting where the user is already a member
		$user = $userHelper->getUser();
		if ($user->isOrgMember($org))
		{
			$session->getFlashBag()->add("info", "You are already a member of this organization and cannot join again!");
			return $this->redirect($this->generateUrl("orgView", ["orgSlug" => $org->getSlug()]));
		}

		$org = $orgMembershipFormHelper->join($org, $userHelper->getUser());
		$session->getFlashBag()->add("success", sprintf("You have successfully joined %s!", $org->getName()));
		return $this->redirect($this->generateUrl("orgView", ["orgSlug" => $org->getSlug()]));
	}
}