<?php

namespace Leapshot\IoBundle\Tests\Helper\Form;

use Symfony\Component\Form\Form,
	Symfony\Component\HttpFoundation\Request,
	Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use Leapshot\IoBundle\Tests\AbstractTest,
	Leapshot\IoBundle\Entity\User as UserEntity;

class UserTest extends AbstractTest
{
	const TEST_PASS = "lol";

	public function testRegister(\Closure $callback = null)
	{
		// services
		$container = $this->getContainer();
		$userFormHelper = $container->get("io.userformhelper");

		list($user, $errors) = $userFormHelper->register(function(Form $form){
			$form->bind([
				"email" => sprintf("adrian+%s@leapshot.com", uniqid()),
				"password" => self::TEST_PASS
			]);
			return $form;
		});

		$this->assertTrue(is_null($errors), sprintf("Register user form errors was not null: %s", var_export($errors, true)));
		$this->assertTrue($user instanceof UserEntity, "User was not an instance of UserEntity");

		if (!is_null($callback))
		{
			$callback($user);
		}

		$userFormHelper->delete($user);
	}

	public function testFinishRegistration(\Closure $callback = null)
	{
		// services
		$container = $this->getContainer();
		$userFormHelper = $container->get("io.userformhelper");

		// simulating a request for sending emails
		$container->enterScope("request");
		$container->set("request", new Request(), "request");

		$this->testRegister(function(UserEntity $user) use($userFormHelper, $callback){
			// finishing registration
			list($user, $form, $errors) = $userFormHelper->finishRegistration($user, function(Form $form){
				$form->bind([
					"first_name" => "Adrian",
					"last_name" => "Parker"
				]);
				return $form;
			}, true);

			$formErrors = $userFormHelper->getErrors($form);
			$this->assertCount(0, $formErrors, sprintf("Finish user registration form had errors: %s", implode(", ", $formErrors)));
			$this->assertTrue($form->isValid(), "Finish user registration form was invalid");
			$this->assertTrue(is_null($errors), sprintf("Finish user registration form errors was not null: %s", var_export($errors, true)));
			$this->assertTrue($user instanceof UserEntity, "User was not an instance of UserEntity");

			if (!is_null($callback))
			{
				$callback($user);
			}
		});
	}

	public function testActivate()
	{
		// services
		$container = $this->getContainer();
		$userFormHelper = $container->get("io.userformhelper");

		$this->testRegister(function(UserEntity $user) use($userFormHelper){
			$user = $userFormHelper->activate($user);

			$this->assertTrue($user instanceof UserEntity, "User was not an instance of UserEntity");
			$this->assertTrue($user->getIsEnabled(), "User was not activated");
		});
	}

	public function testForgotPassword()
	{
		// services
		$container = $this->getContainer();
		$userFormHelper = $container->get("io.userformhelper");

		// simulating a request for sending emails
		$container->enterScope("request");
		$container->set("request", new Request(), "request");

		$this->testRegister(function(UserEntity $user) use($userFormHelper){
			list($user, $form, $errors) = $userFormHelper->forgotPassword(function(Form $form) use($user){
				$form->bind([
					"email" => $user->getEmail()
				]);
				return $form;
			}, true);

			$formErrors = $userFormHelper->getErrors($form);
			$this->assertCount(0, $formErrors, sprintf("Forgot password form had errors: %s", implode(", ", $formErrors)));
			$this->assertTrue($form->isValid(), "Forgot password form was invalid");
			$this->assertTrue(is_null($errors), sprintf("Forgot password errors was not null: %s", var_export($errors, true)));
		});
	}

	public function testResetPassword()
	{
		// services
		$container = $this->getContainer();
		$userFormHelper = $container->get("io.userformhelper");

		$this->testRegister(function(UserEntity $user) use($userFormHelper){
			list($user, $form, $errors) = $userFormHelper->resetPassword($user, function(Form $form){
				$form->bind([
					"new_password" => [
						"first" => "aaaaaa",
						"second" => "aaaaaa"
					]
				]);
				return $form;
			}, true);

			$formErrors = $userFormHelper->getErrors($form);
			$this->assertCount(0, $formErrors, sprintf("Reset password form had errors: %s", implode(", ", $formErrors)));
			$this->assertTrue($form->isValid(), "Reset password form was invalid");
			$this->assertTrue(is_null($errors), sprintf("Reset password errors was not null: %s", var_export($errors, true)));
		});
	}

	public function testChangePassword()
	{
		// services
		$container = $this->getContainer();
		$userFormHelper = $container->get("io.userformhelper");
		$securityContext = $container->get("security.context");

		$this->testRegister(function(UserEntity $user) use($userFormHelper, $securityContext){
			// setting up the security context token (because SF2's UserPassword constraint requires it)
			$token = new UsernamePasswordToken($user, null, "secured", $user->getRoles());
			$securityContext->setToken($token);

			// running over the form
			list($user, $form, $errors) = $userFormHelper->changePassword($user, function(Form $form){
				$form->bind([
					"password" => self::TEST_PASS,
					"new_password" => [
						"first" => "aaaaaa",
						"second" => "aaaaaa"
					]
				]);
				return $form;
			}, true);

			$formErrors = $userFormHelper->getErrors($form);
			$this->assertCount(0, $formErrors, sprintf("Change password form had errors: %s", implode(", ", $formErrors)));
			$this->assertTrue($form->isValid(), "Change password form was invalid");
			$this->assertTrue(is_null($errors), sprintf("Change password errors was not null: %s", var_export($errors, true)));
		});
	}

	public function testUpdate()
	{
		// services
		$container = $this->getContainer();
		$userFormHelper = $container->get("io.userformhelper");

		$this->testFinishRegistration(function(UserEntity $user) use($userFormHelper){
			list($user, $form, $errors) = $userFormHelper->update($user, function(Form $form) use($user){
				$form->bind([
					"first_name" => $user->getFirstName(),
					"last_name" => $user->getLastName(),
					"email" => $user->getEmail(),
					"recoveryPhone" => "613-555-5555"
				]);
				return $form;
			}, true);

			$formErrors = $userFormHelper->getErrors($form);
			$this->assertCount(0, $formErrors, sprintf("Update form had errors: %s", implode(", ", $formErrors)));
			$this->assertTrue($form->isValid(), "Update form was invalid");
			$this->assertTrue(is_null($errors), sprintf("Update errors was not null: %s", var_export($errors, true)));
		});
	}
}