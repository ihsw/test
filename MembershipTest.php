<?php

namespace Leapshot\IoBundle\Tests\Helper\Form\Organization;

use Symfony\Component\Form\Form,
	Symfony\Component\HttpFoundation\Request;

use Leapshot\IoBundle\Tests\AbstractTest,
	Leapshot\IoBundle\Entity\Organization as OrganizationEntity,
	Leapshot\IoBundle\Entity\Invite as InviteEntity,
	Leapshot\IoBundle\Entity\User as UserEntity,
	Leapshot\IoBundle\Entity\MembershipRequest as MembershipRequestEntity,
	Leapshot\IoBundle\Helper\Form\Organization as OrgFormHelper;

class MembershipTest extends AbstractTest
{
	private function handleOrganization(\Closure $callback)
	{
		// services
		$container = $this->getContainer();
		$orgFormHelper = $container->get("io.orgformhelper");

		// generating an org
		list($org, $form, $errors) = $orgFormHelper->createOrganization(function(Form $form){
			$form->bind([
				"name" => uniqid("test-org-name"),
				"description" => uniqid("test-org-description")
			]);
			return $form;
		}, true);
		$this->assertTrue($org instanceof OrganizationEntity, "Org was not an instance of OrganizationEntity");

		$callback($org);

		$orgFormHelper->deleteOrganization($org);
	}

	public function handleUser(\Closure $callback)
	{
		// services
		$container = $this->getContainer();
		$userFormHelper = $container->get("io.userformhelper");

		// generating a user
		list($user, $errors) = $userFormHelper->register(function(Form $form){
			$form->bind([
				"email" => sprintf("adrian+%s@leapshot.com", uniqid()),
				"password" => "lol"
			]);
			return $form;
		});
		$this->assertTrue($user instanceof UserEntity, "User was not an instance of UserEntity");

		$callback($user);

		$userFormHelper->delete($user);
	}

	public function testInvite()
	{
		// services
		$container = $this->getContainer();
		$orgMembershipFormHelper = $container->get("io.orgmembershipformhelper");

		// simulating a request for sending emails
		$container->enterScope("request");
		$container->set("request", new Request(), "request");

		$this->handleOrganization(function(OrganizationEntity $org) use($orgMembershipFormHelper){
			// simulating an org-invite
			list($invite, $form, $errors) = $orgMembershipFormHelper->invite($org, function(Form $form){
				$form->bind(["email" => sprintf("adrian+%s@leapshot.com", uniqid())]);
				return $form;
			}, true);

			$formErrors = $orgMembershipFormHelper->getErrors($form);
			$this->assertCount(0, $formErrors, sprintf("Org invite form has errors: %s", implode(", ", $formErrors)));
			$this->assertTrue($form->isValid(), "Org invite form wasn't valid");
			$this->assertTrue(is_null($errors), sprintf("Org invite form errors was not null: %s", var_export($errors, true)));
			$this->assertTrue($invite instanceof InviteEntity, "Invite was not an instance of Invite");
		});
	}

	public function testRevokeInvite()
	{
		// services
		$container = $this->getContainer();
		$orgMembershipFormHelper = $container->get("io.orgmembershipformhelper");

		// simulating a request for sending emails
		$container->enterScope("request");
		$container->set("request", new Request(), "request");

		$this->handleOrganization(function(OrganizationEntity $org) use($orgMembershipFormHelper){
			// simulating an org-invite
			list($invite, $form, $errors) = $orgMembershipFormHelper->invite($org, function(Form $form){
				$form->bind(["email" => sprintf("adrian+%s@leapshot.com", uniqid())]);
				return $form;
			}, true);
			$this->assertTrue($invite instanceof InviteEntity, "Invite was not an instance of Invite");

			$beforeOrgInviteIds = array_map(function($invite){ return $invite->getId(); }, iterator_to_array($org->getInvites()));
			$org = $orgMembershipFormHelper->revokeInvite($invite);
			$orgInviteIds = array_map(function($invite){ return $invite->getId(); }, iterator_to_array($org->getInvites()));
			$this->assertTrue(count($orgInviteIds) === count($beforeOrgInviteIds)-1, "Number of org invites did not decrement");
		});
	}

	public function testAddAdmin(\Closure $callback = null)
	{
		// services
		$container = $this->getContainer();
		$orgMembershipFormHelper = $container->get("io.orgmembershipformhelper");

		$this->handleUser(function(UserEntity $user) use($orgMembershipFormHelper, $callback){
			$this->handleOrganization(function(OrganizationEntity $org) use($orgMembershipFormHelper, $user, $callback){
				list($org, $user) = $orgMembershipFormHelper->addAdmin($org, $user);

				$this->assertTrue($org instanceof OrganizationEntity, "Org was not an instance of OrganizationEntity");
				$this->assertTrue($user instanceof UserEntity, "User was not an instance of UserEntity");

				$userId = $user->getId();
				$orgId = $org->getId();
				$userOrgsAdministered = array_map(function($org){ return $org->getId(); }, iterator_to_array($user->getAdminOrganizations()));
				$orgUserAdmins = array_map(function($user){ return $user->getId(); }, iterator_to_array($org->getUserAdmins()));
				$this->assertTrue(in_array($userId, $orgUserAdmins), "User was not found in the list of org-user-admins");
				$this->assertTrue(in_array($orgId, $userOrgsAdministered), "Org was not found in the list of orgs administered by this user");

				if (!is_null($callback))
				{
					$callback($org, $user);
				}
			});
		});
	}

	public function testRemoveAdmin()
	{
		// services
		$container = $this->getContainer();
		$orgMembershipFormHelper = $container->get("io.orgmembershipformhelper");

		$this->testAddAdmin(function(OrganizationEntity $org, UserEntity $user) use($orgMembershipFormHelper){
			list($org, $user) = $orgMembershipFormHelper->removeAdmin($org, $user);

			$this->assertTrue($org instanceof OrganizationEntity, "Org was not an instance of OrganizationEntity");
			$this->assertTrue($user instanceof UserEntity, "User was not an instance of UserEntity");

			$userId = $user->getId();
			$orgId = $org->getId();
			$userOrgsAdministered = array_map(function($org){ return $org->getId(); }, iterator_to_array($user->getAdminOrganizations()));
			$orgUserAdmins = array_map(function($user){ return $user->getId(); }, iterator_to_array($org->getUserAdmins()));
			$this->assertFalse(in_array($userId, $orgUserAdmins), "User was found in the list of org-user-admins");
			$this->assertFalse(in_array($orgId, $userOrgsAdministered), "Org was found in the list of orgs administered by this user");
		});
	}

	public function testRemoveMember()
	{
		// services
		$container = $this->getContainer();
		$orgMembershipFormHelper = $container->get("io.orgmembershipformhelper");

		$this->testJoin(function(OrganizationEntity $org, UserEntity $user) use($orgMembershipFormHelper){
			list($org, $user) = $orgMembershipFormHelper->removeMember($org, $user);

			$this->assertTrue($org instanceof OrganizationEntity, "Org was not an instance of OrganizationEntity");
			$this->assertTrue($user instanceof UserEntity, "User was not an instance of UserEntity");
		});
	}

	public function testTransferOwnership()
	{
		// services
		$container = $this->getContainer();
		$orgMembershipFormHelper = $container->get("io.orgmembershipformhelper");

		$this->handleUser(function(UserEntity $user) use($orgMembershipFormHelper){
			$this->handleOrganization(function(OrganizationEntity $org) use($orgMembershipFormHelper, $user){
				list($org, $user) = $orgMembershipFormHelper->transferOwnership($org, $user);

				$this->assertTrue($org instanceof OrganizationEntity, "Org was not an instance of OrganizationEntity");
				$this->assertTrue($user instanceof UserEntity, "User was not an instance of UserEntity");

				$this->assertTrue($org->getOwnerUser()->getId() === $user->getId(), "Org owner user-id was not equal to the returned user-id");
				$ownedOrgIds = array_map(function($org){ return $org->getId(); }, iterator_to_array($user->getOwnedOrganizations()));
				$this->assertTrue(in_array($org->getId(), $ownedOrgIds), "Org was not in the list of owned organizations");
			});
		});
	}

	public function testRequestMembership()
	{
		// services
		$container = $this->getContainer();
		$orgMembershipFormHelper = $container->get("io.orgmembershipformhelper");

		// simulating a request for sending emails
		$container->enterScope("request");
		$container->set("request", new Request(), "request");

		$this->handleUser(function(UserEntity $user) use($orgMembershipFormHelper){
			$this->handleOrganization(function(OrganizationEntity $org) use($orgMembershipFormHelper, $user){
				$membershipRequest = $orgMembershipFormHelper->requestMembership($org, $user);

				$this->assertTrue($membershipRequest instanceof MembershipRequestEntity, "Membership-request was not an instance of MembershipRequestEntity");
			});
		});
	}

	public function testAcceptMembershipRequest()
	{
		// services
		$container = $this->getContainer();
		$orgMembershipFormHelper = $container->get("io.orgmembershipformhelper");

		// simulating a request for sending emails
		$container->enterScope("request");
		$container->set("request", new Request(), "request");

		$this->handleUser(function(UserEntity $user) use($orgMembershipFormHelper){
			$this->handleOrganization(function(OrganizationEntity $org) use($orgMembershipFormHelper, $user){
				$membershipRequest = $orgMembershipFormHelper->requestMembership($org, $user);
				$this->assertTrue($membershipRequest instanceof MembershipRequestEntity, "Membership-request was not an instance of MembershipRequestEntity");

				$membershipRequest = $orgMembershipFormHelper->acceptMembershipRequest($membershipRequest);
				$this->assertTrue($membershipRequest instanceof MembershipRequestEntity, "Membership-request was not an instance of MembershipRequestEntity");
			});
		});
	}

	public function testRejectMembershipRequest()
	{
		// services
		$container = $this->getContainer();
		$orgMembershipFormHelper = $container->get("io.orgmembershipformhelper");

		// simulating a request for sending emails
		$container->enterScope("request");
		$container->set("request", new Request(), "request");

		$this->handleUser(function(UserEntity $user) use($orgMembershipFormHelper){
			$this->handleOrganization(function(OrganizationEntity $org) use($orgMembershipFormHelper, $user){
				$membershipRequest = $orgMembershipFormHelper->requestMembership($org, $user);
				$this->assertTrue($membershipRequest instanceof MembershipRequestEntity, "Membership-request was not an instance of MembershipRequestEntity");

				$membershipRequest = $orgMembershipFormHelper->rejectMembershipRequest($membershipRequest);
				$this->assertTrue($membershipRequest instanceof MembershipRequestEntity, "Membership-request was not an instance of MembershipRequestEntity");
			});
		});
	}

	public function testResendInvite()
	{
		// services
		$container = $this->getContainer();
		$orgMembershipFormHelper = $container->get("io.orgmembershipformhelper");

		// simulating a request for sending emails
		$container->enterScope("request");
		$container->set("request", new Request(), "request");

		$this->handleOrganization(function(OrganizationEntity $org) use($orgMembershipFormHelper){
			list($invite, $form, $errors) = $orgMembershipFormHelper->invite($org, function(Form $form){
				$form->bind(["email" => sprintf("adrian+%s@leapshot.com", uniqid())]);
				return $form;
			}, true);
			$this->assertTrue($invite instanceof InviteEntity, "Invite was not an instance of Invite");

			$invite = $orgMembershipFormHelper->resendInvite($invite);
			$this->assertTrue($invite instanceof InviteEntity, "Invite was not an instance of Invite");
			
		});
	}

	public function testJoin(\Closure $callback = null)
	{
		// services
		$container = $this->getContainer();
		$orgMembershipFormHelper = $container->get("io.orgmembershipformhelper");

		// simulating a request for sending emails
		$container->enterScope("request");
		$container->set("request", new Request(), "request");

		$this->handleUser(function(UserEntity $user) use($orgMembershipFormHelper, $callback){
			$this->handleOrganization(function(OrganizationEntity $org) use($orgMembershipFormHelper, $user, $callback){
				$org = $orgMembershipFormHelper->join($org, $user);
				$this->assertTrue($org instanceof OrganizationEntity, "Org was not an instance of OrganizationEntity");

				if (!is_null($callback))
				{
					$callback($org, $user);
				}
			});
		});
	}

	public function testLeave()
	{
		// services
		$container = $this->getContainer();
		$orgMembershipFormHelper = $container->get("io.orgmembershipformhelper");

		$this->testJoin(function(OrganizationEntity $org, UserEntity $user) use($orgMembershipFormHelper){
			$user = $orgMembershipFormHelper->leave($user, $org);
			$this->assertTrue($user instanceof UserEntity, "Leave user was not an instance of UserEntity");
		});
	}

	public function testAcceptInvitation()
	{
		// services
		$container = $this->getContainer();
		$orgMembershipFormHelper = $container->get("io.orgmembershipformhelper");

		// simulating a request for sending emails
		$container->enterScope("request");
		$container->set("request", new Request(), "request");

		$this->handleUser(function(UserEntity $user) use($orgMembershipFormHelper){
			$this->handleOrganization(function(OrganizationEntity $org) use($orgMembershipFormHelper, $user){
				list($invite, $form, $errors) = $orgMembershipFormHelper->invite($org, function(Form $form) use($user){
					$form->bind(["email" => $user->getEmail()]);
					return $form;
				}, true);
				$this->assertTrue($invite instanceof InviteEntity, "Invite was not an instance of Invite");
				$this->assertTrue($invite->getStatus() === InviteEntity::PENDING, "Invite status was not PENDING");

				$invite = $orgMembershipFormHelper->acceptInvitation($invite);
				$this->assertTrue($invite instanceof InviteEntity, "Invite was not an instance of Invite");
				$this->assertTrue($invite->getStatus() === InviteEntity::ACCEPTED, "Invite status was not ACCEPTED");
			});
		});
	}

	public function testRejectInvitation()
	{
		// services
		$container = $this->getContainer();
		$orgMembershipFormHelper = $container->get("io.orgmembershipformhelper");

		// simulating a request for sending emails
		$container->enterScope("request");
		$container->set("request", new Request(), "request");

		$this->handleUser(function(UserEntity $user) use($orgMembershipFormHelper){
			$this->handleOrganization(function(OrganizationEntity $org) use($orgMembershipFormHelper, $user){
				list($invite, $form, $errors) = $orgMembershipFormHelper->invite($org, function(Form $form) use($user){
					$form->bind(["email" => $user->getEmail()]);
					return $form;
				}, true);
				$this->assertTrue($invite instanceof InviteEntity, "Invite was not an instance of Invite");
				$this->assertTrue($invite->getStatus() === InviteEntity::PENDING, "Invite status was not PENDING");

				$invite = $orgMembershipFormHelper->rejectInvitation($invite);
				$this->assertTrue($invite instanceof InviteEntity, "Invite was not an instance of Invite");
				$this->assertTrue($invite->getStatus() === InviteEntity::REJECTED, "Invite status was not REJECTED");
			});
		});
	}
}