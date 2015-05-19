<?php

namespace Leapshot\IoBundle\Helper\Form\Organization;

use Doctrine\Bundle\DoctrineBundle\Registry;

use Leapshot\IoBundle\Helper\Form\AbstractForm,
	Leapshot\IoBundle\Helper\User as UserHelper,
	Leapshot\IoBundle\Entity\Organization as OrganizationEntity,
	Leapshot\IoBundle\Entity\Invite as InviteEntity,
	Leapshot\IoBundle\Entity\MembershipRequest as MembershipRequestEntity,
	Leapshot\IoBundle\Entity\User as UserEntity,
	Leapshot\IoBundle\Form\Type\OrganizationInviteType;

class Membership extends AbstractForm
{
	private $doctrine;
	private $userHelper;
	private $mailer;
	private $twig;
	private $ioConfig;

	public function __construct(Registry $doctrine, UserHelper $userHelper, \Swift_Mailer $mailer,
		\Twig_Environment $twig, array $ioConfig)
	{
		$this->doctrine = $doctrine;
		$this->userHelper = $userHelper;
		$this->mailer = $mailer;
		$this->twig = $twig;
		$this->ioConfig = $ioConfig;
	}

	/**
	 * @param OrganizationEntity $org
	 * @param \Closure $bindForm func(Form) Form
	 * @return array Invite|null, Form, []string|null
	 */
	public function invite(OrganizationEntity $org, \Closure $bindForm, $errorBubbling = false)
	{
		// misc
		$user = $this->userHelper->getUser();

		// running over the form
		$invite = new InviteEntity();
		$dateSent = new \DateTime();
		$invite->setSenderUser($user)
			->setOrganization($org)
			->setStatus(InviteEntity::PENDING)
			->setDateSent($dateSent)
			->setHash(md5(sprintf("%s-%s-%s", mt_rand(), $dateSent->format("U"), $org->getId())));
		list($form, $errors) = $this->bindAndRunValidation(new OrganizationInviteType($errorBubbling), $bindForm, $invite);
		if (!is_null($errors))
		{
			return [null, $form, $errors];
		}

		// misc
		$doctrine = $this->doctrine;
		$em = $doctrine->getManager();
		$inviteRepo = $doctrine->getRepository("LeapshotIoBundle:Invite");
		$addError = function($message) use($form){
			return $this->addError($form, "email", $message);
		};

		// inviting new users
		$email = $form["email"]->getData();
		$recipientUser = $doctrine->getRepository("LeapshotIoBundle:User")->findOneByAnyEmail($email);
		if (is_null($recipientUser))
		{
			if ($inviteRepo->emailHasPendingInvitesToOrg($email, $org))
			{
				$addError("That email already has a pending invite to this community. Please choose another.");
				return [null, $form, $this->getErrors($form)];
			}

			// persisting the new invite entity
			$invite->setRecipientEmail($email);
			$em->persist($invite);
			$em->flush();

			// updating the org
			$org->addInvite($invite);

			// sending them an email
			$messageBody = $this->twig->render("LeapshotIoBundle:Organization/_email:invite-new-user.html.twig", ["invite" => $invite]);
			$message = \Swift_Message::newInstance()
				->setSubject("Good news, you've been invited to join a community on CommunityPOP.com")
				->setFrom($this->ioConfig["emails"]["support"])
				->setTo($email)
				->setBody($messageBody, "text/html");
			$this->mailer->send($message);

			return [$invite, $form, null];
		}

		// rejecting users whom already have an invitation to this org
		if ($inviteRepo->userHasPendingInvitesToOrg($recipientUser, $org))
		{
			$addError("That user already has a pending invite to this community. Please choose another.");
			return [null, $form, $this->getErrors($form)];
		}

		// rejecting users whom already are members
		if ($doctrine->getRepository("LeapshotIoBundle:Organization")->hasUserMember($org, $recipientUser))
		{
			$addError("That user is already a member of this community. Please choose another.");
			return [null, $form, $this->getErrors($form)];
		}

		// inviting the user
		$invite->setRecipientEmail($email)
			->setRecipientUser($recipientUser);
		$em->persist($invite);
		$em->flush();

		// updating the org
		$org->addInvite($invite);

		return [$invite, $form, null];
	}

	/**
	 * @param OrganizationEntity
	 * @param UserEntity
	 * @return array OrganizationEntity, UserEntity
	 */
	public function addAdmin(OrganizationEntity $org, UserEntity $user)
	{
		$em = $this->doctrine->getManager();
		$org->addUserAdmin($user);
		$user->addAdminOrganization($org);
		$em->persist($org);
		$em->flush();

		return [$org, $user];
	}

	/**
	 * @param OrganizationEntity
	 * @param UserEntity
	 * @return array OrganizationEntity, UserEntity
	 */
	public function removeAdmin(OrganizationEntity $org, UserEntity $user)
	{
		$em = $this->doctrine->getManager();
		$org->removeUserAdmin($user);
		$user->removeAdminOrganization($org);
		$em->persist($org);
		$em->flush();

		return [$org, $user];
	}

	/**
	 * @param OrganizationEntity
	 * @param UserEntity
	 * @return array OrganizationEntity, UserEntity
	 */
	public function removeMember(OrganizationEntity $org, UserEntity $user)
	{
		$em = $this->doctrine->getManager();
		$org->removeUserAdmin($user);
		$org->removeUserMember($user);
		$user->removeAdminOrganization($org);
		$user->removeParentOrganization($org);
		$em->persist($org);
		$em->flush();

		return [$org, $user];
	}

	/**
	 * @param OrganizationEntity
	 * @param UserEntity
	 * @return array OrganizationEntity, UserEntity
	 */
	public function transferOwnership(OrganizationEntity $org, UserEntity $user)
	{
		$currentOwnerUser = $org->getOwnerUser();

		$em = $this->doctrine->getManager();
		$org->setOwnerUser($user);
		$user->addOwnedOrganization($org);
		$em->persist($org);

		$isCurrentOwnerUserAdmin = false;
		if (count($org->getUserAdmins()) > 0)
		{
			$isCurrentOwnerUserAdmin = array_reduce(iterator_to_array($org->getUserAdmins()), function($isCurrentOwnerUserAdmin, $userAdmin) use($currentOwnerUser){
				return $isCurrentOwnerUserAdmin || $userAdmin->getId() === $currentOwnerUser->getId();
			}, false);
		}
		if (!$isCurrentOwnerUserAdmin)
		{
			$org->addUserAdmin($currentOwnerUser);
			$currentOwnerUser->addAdminOrganization($org);
			$em->persist($org);
		}

		$em->flush();

		return [$org, $user];
	}

	/**
	 * @param InviteEntity
	 * @return OrganizationEntity
	 */
	public function revokeInvite(InviteEntity $invite)
	{
		$org = $invite->getOrganization();
		$org->removeInvite($invite);

		$em = $this->doctrine->getManager();
		$em->remove($invite);
		$em->flush();

		return $org;
	}

	/**
	 * @param OrganizationEntity
	 * @param UserEntity
	 * @return MembershipRequestEntity
	 */
	public function requestMembership(OrganizationEntity $org, UserEntity $user)
	{
		// creating the membership-request
		$em = $this->doctrine->getManager();
		$membershipRequest = new MembershipRequestEntity();
		$membershipRequest->setStatus(MembershipRequestEntity::PENDING)
			->setDateSent(new \DateTime())
			->setOrganization($org)
			->setSenderUser($user);
		$em->persist($membershipRequest);
		$em->flush();

		$org->addMembershipRequest($membershipRequest);

		// emailing the org-owner that a new user has requested access to the organization
		$subject = "Good news, someone wants to join your community on CommunityPOP";
		$from = $this->ioConfig["emails"]["support"];
		$to = $org->getOwnerUser()->getEmail();
		$messageBody = $this->twig->render("LeapshotIoBundle:Organization/_email:membership-request.html.twig", [
			"org" => $org,
			"user" => $user
		]);
		$message = \Swift_Message::newInstance()
			->setSubject($subject)
			->setFrom($from)
			->setTo($to)
			->setBody($messageBody, "text/html");
		$this->mailer->send($message);

		return $membershipRequest;
	}

	/**
	 * @param MembershipRequestEntity
	 * @return MembershipRequestEntity
	 */
	public function acceptMembershipRequest(MembershipRequestEntity $membershipRequest)
	{
		// misc
		$em = $this->doctrine->getManager();
		$org = $membershipRequest->getOrganization();
		$senderUser = $membershipRequest->getSenderUser();

		// adding the sender-user to the organization
		$org->addUserMember($senderUser);
		$em->persist($org);

		// updating the request with appropriate meta-data
		$membershipRequest->setStatus(MembershipRequestEntity::ACCEPTED)
			->setDateResolved(new \DateTime());
		$em->persist($membershipRequest);
		$em->flush();

		// sending the sender-user an email notifying them of their acceptance
		$subject = "Good news, community membership accepted on CommunityPOP";
		$from = $this->ioConfig["emails"]["support"];
		$to = $senderUser->getEmail();
		$messageBody = $this->twig->render("LeapshotIoBundle:Organization/_email:accepted-membership.html.twig", [
			"user" => $senderUser,
			"org" => $org
		]);
		$message = \Swift_Message::newInstance()
			->setSubject($subject)
			->setFrom($from)
			->setTo($to)
			->setBody($messageBody, "text/html");
		$this->mailer->send($message);

		return $membershipRequest;
	}

	/**
	 * @param MembershipRequestEntity
	 * @return MembershipRequestEntity
	 */
	public function rejectMembershipRequest(MembershipRequestEntity $membershipRequest)
	{
		// misc
		$em = $this->doctrine->getManager();
		$org = $membershipRequest->getOrganization();
		$senderUser = $membershipRequest->getSenderUser();

		// updating the request with appropriate meta-data
		$membershipRequest->setStatus(MembershipRequestEntity::REJECTED)
			->setDateResolved(new \DateTime());
		$em->persist($membershipRequest);
		$em->flush();

		// sending the sender-user an email notifying them of their rejection
		$subject = "Sorry my friend, membership not approved";
		$from = $this->ioConfig["emails"]["support"];
		$to = $senderUser->getEmail();
		$messageBody = $this->twig->render("LeapshotIoBundle:Organization/_email:rejected-membership.html.twig", [
			"user" => $senderUser,
			"org" => $org
		]);
		$message = \Swift_Message::newInstance()
			->setSubject($subject)
			->setFrom($from)
			->setTo($to)
			->setBody($messageBody, "text/html");
		$this->mailer->send($message);

		return $membershipRequest;
	}

	/**
	 * @param InviteEntity
	 * @return InviteEntity
	 */
	public function resendInvite(InviteEntity $invite)
	{
		$email = $invite->getRecipientEmail();
		$messageBody = $this->twig->render("LeapshotIoBundle:Organization/_email:invite-new-user.html.twig", ["invite" => $invite]);
		$message = \Swift_Message::newInstance()
			->setSubject("Hey, you looking for a community invitation on CommunityPOP? Resending to be sure...")
			->setFrom($this->ioConfig["emails"]["support"])
			->setTo($email)
			->setBody($messageBody, 'text/html');
		$this->mailer->send($message);

		return $invite;
	}

	/**
	 * @param OrganizationEntity
	 * @param UserEntity
	 * @return OrganizationEntity
	 */
	public function join(OrganizationEntity $org, UserEntity $user)
	{
		// adding the user to the organization
		$em = $this->doctrine->getManager();
		$org->addUserMember($user);
		$user->addParentOrganization($org);
		$em->persist($org);
		$em->flush();

		// emailing the org-owner that a new user has joined their organization
		$subject = "Great news, someone has joined your community";
		$from = $this->ioConfig["emails"]["support"];
		$to = $org->getOwnerUser()->getEmail();
		$messageBody = $this->twig->render("LeapshotIoBundle:Organization/_email:user-joined.html.twig", [
			"user" => $user,
			"org" => $org
		]);
		$message = \Swift_Message::newInstance()
			->setSubject($subject)
			->setFrom($from)
			->setTo($to)
			->setBody($messageBody, "text/html");
		$this->mailer->send($message);

		return $org;
	}

	/**
	 * @param Inviteentity
	 * @return InviteEntity
	 */
	public function acceptInvitation(InviteEntity $invite)
	{
		// misc
		$em = $this->doctrine->getManager();
		
		// updating the invite status
		$invite->setStatus(InviteEntity::ACCEPTED);
		$em->persist($invite);

		// adding the user to the org
		$org = $invite->getOrganization();
		$org->addUserMember($invite->getRecipientUser());
		$em->persist($org);

		// flushing
		$em->flush();

		return $invite;
	}

	/**
	 * @param InviteEntity
	 * @return InviteEntity
	 */
	public function rejectInvitation(InviteEntity $invite)
	{
		$em = $this->doctrine->getManager();
		$invite->setStatus(InviteEntity::REJECTED);
		$em->persist($invite);
		$em->flush();

		return $invite;
	}

	/**
	 * @param UserEntity
	 * @param OrganizationEntity
	 * @return UserEntity
	 */
	public function leave(UserEntity $user, OrganizationEntity $org)
	{
		$org->removeUserMember($user);
		$org->removeUserAdmin($user);
		$user->removeParentOrganization($org);
		$user->removeAdminOrganization($org);

		$em = $this->doctrine->getManager();
		$em->persist($org);
		$em->flush();

		return $user;
	}
}