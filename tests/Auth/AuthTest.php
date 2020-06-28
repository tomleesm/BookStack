<?php namespace Tests\Auth;

use BookStack\Auth\Role;
use BookStack\Auth\User;
use BookStack\Entities\Page;
use BookStack\Notifications\ConfirmEmail;
use BookStack\Notifications\ResetPassword;
use BookStack\Settings\SettingService;
use DB;
use Hash;
use Illuminate\Support\Facades\Notification;
use Tests\BrowserKitTest;

class AuthTest extends BrowserKitTest
{

    public function test_auth_working()
    {
        $this->visit('/')
            ->seePageIs('/login');
    }

    // 測試預設的帳號密碼是否有效
    // login() 定義在這個 php 檔案最底下
    public function test_login()
    {
        $this->login('admin@admin.com', 'password')
            ->seePageIs('/');
    }

    // 設定開放 app，則進入根目錄，不再自動跳轉到 /login，而是停在 /
    public function test_public_viewing()
    {
        // 設定開放 app
        $this->setSettings(['app-public' => 'true']);
        // 進入根目錄，不再自動跳轉到 /login，而是停在 /
        $this->visit('/')
            ->seePageIs('/')
            ->see('Log In');
    }

    // 設定可以註冊會員，則進入 /login ，會顯示註冊連結
    public function test_registration_showing()
    {
        // Ensure registration form is showing
        // 設定可以註冊會員
        $this->setSettings(['registration-enabled' => 'true']);
        $this->visit('/login')
            ->see('Sign up')
            ->click('Sign up')
            ->seePageIs('/register');
    }

    public function test_normal_registration()
    {
        // Set settings and get user instance
        // 設定可以註冊會員
        $this->setSettings(['registration-enabled' => 'true']);
        // 隨機產生一個 User 物件
        // database/factories/ModelFactory.php 第一個 factory
        $user = factory(User::class)->make();

        // Test form and ensure user is created
        // 輸入姓名、E-mail 和密碼以註冊會員
        $this->visit('/register')
            ->see('Sign Up')
            ->type($user->name, '#name')
            ->type($user->email, '#email')
            ->type($user->password, '#password')
            ->press('Create Account')
            // 註冊後進入首頁，有看到註冊的會員名稱
            ->seePageIs('/')
            ->see($user->name)
            // 會員資料有新增到資料庫
            ->seeInDatabase('users', ['name' => $user->name, 'email' => $user->email]);
    }

    // 註冊會員時，如果欄位是空的，按下 Create Account 時會顯示錯誤訊息
    public function test_empty_registration_redirects_back_with_errors()
    {
        // Set settings and get user instance
        $this->setSettings(['registration-enabled' => 'true']);

        // Test form and ensure user is created
        $this->visit('/register')
            ->press('Create Account')
            ->see('The name field is required')
            ->seePageIs('/register');
    }

    // 測試註冊會員時的欄位驗證功能
    public function test_registration_validation()
    {
        $this->setSettings(['registration-enabled' => 'true']);

        $this->visit('/register')
            ->type('1', '#name')
            ->type('1', '#email')
            ->type('1', '#password')
            ->press('Create Account')
            ->see('The name must be at least 2 characters.')
            ->see('The email must be a valid email address.')
            ->see('The password must be at least 8 characters.')
            ->seePageIs('/register');
    }

    // 預設 /login 不顯示註冊連結
    public function test_sign_up_link_on_login()
    {
        $this->visit('/login')
            ->dontSee('Sign up');

        $this->setSettings(['registration-enabled' => 'true']);

        $this->visit('/login')
            ->see('Sign up');
    }

    public function test_confirmed_registration()
    {
        // Fake notifications
        // 用 Mock 模擬通知。參考 Laravel 官方文件 testing/Mocking/Notification Fake
        // 啓用一個 Mock
        Notification::fake();

        // Set settings and get user instance
        // 設定可以註冊，以及註冊後顯示確認頁面
        $this->setSettings([
            'registration-enabled' => 'true',
            'registration-confirmation' => 'true']
        );
        $user = factory(User::class)->make();

        // Go through registration process
        // 註冊會員
        $this->visit('/register')
            ->see('Sign Up')
            ->type($user->name, '#name')
            ->type($user->email, '#email')
            ->type($user->password, '#password')
            ->press('Create Account')
            // 顯示確認，而不是預設的首頁
            ->seePageIs('/register/confirm')
            // 確認有新增註冊的會員資料到資料庫
            ->seeInDatabase('users', ['name' => $user->name, 'email' => $user->email, 'email_confirmed' => false]);

        // Ensure notification sent
        $dbUser = User::where('email', '=', $user->email)->first();
        // 用 Mock 模擬通知。參考 Laravel 官方文件 testing/Mocking/Notification Fake
        // 通知已送出
        Notification::assertSentTo($dbUser, ConfirmEmail::class);

        // Test access and resend confirmation email
        // 登入後，頁面停留在等候確認註冊的 E-mail
        $this->login($user->email, $user->password)
            ->seePageIs('/register/confirm/awaiting')
            ->see('Resend')
            ->visit('/books')
            ->seePageIs('/register/confirm/awaiting')
            // 重送確認 E-mail
            ->press('Resend Confirmation Email');

        // Get confirmation and confirm notification matches
        // 收到通知，且 token 和資料庫的一樣
        $emailConfirmation = DB::table('email_confirmations')->where('user_id', '=', $dbUser->id)->first();
        Notification::assertSentTo($dbUser, ConfirmEmail::class, function($notification, $channels) use ($emailConfirmation) {
            return $notification->token === $emailConfirmation->token;
        });

        // Check confirmation email confirmation activation.
        // 點選確認的網址以確認，接著自動登入會員，並進入首頁
        $this->visit('/register/confirm/' . $emailConfirmation->token)
            ->seePageIs('/')
            ->see($user->name)
            ->notSeeInDatabase('email_confirmations', ['token' => $emailConfirmation->token])
            ->seeInDatabase('users', ['name' => $dbUser->name, 'email' => $dbUser->email, 'email_confirmed' => true]);
    }

    // 限制只能用某個網域的 E-mail 註冊會員
    public function test_restricted_registration()
    {
        $this->setSettings(['registration-enabled' => 'true',
                            'registration-confirmation' => 'true',
                            'registration-restrict' => 'example.com']);

        $user = factory(User::class)->make();
        // Go through registration process
        $this->visit('/register')
            ->type($user->name, '#name')
            ->type($user->email, '#email')
            ->type($user->password, '#password')
            ->press('Create Account')
            ->seePageIs('/register')
            ->dontSeeInDatabase('users', ['email' => $user->email])
            ->see('That email domain does not have access to this application');

        $user->email = 'barry@example.com';

        $this->visit('/register')
            ->type($user->name, '#name')
            ->type($user->email, '#email')
            ->type($user->password, '#password')
            ->press('Create Account')
            ->seePageIs('/register/confirm')
            ->seeInDatabase('users', ['name' => $user->name, 'email' => $user->email, 'email_confirmed' => false]);

        $this->visit('/')->seePageIs('/login')
            ->type($user->email, '#email')
            ->type($user->password, '#password')
            ->press('Log In')
            ->seePageIs('/register/confirm/awaiting')
            ->seeText('Email Address Not Confirmed');
    }

    // 設定不用註冊確認，且限制只能用某個網域的 E-mail 註冊會員
    // 和上一個測試一樣，只是開頭的設定 registration-confirmation 不同
    public function test_restricted_registration_with_confirmation_disabled()
    {
        $this->setSettings(['registration-enabled' => 'true',
                            'registration-confirmation' => 'false',
                            'registration-restrict' => 'example.com']);
        $user = factory(User::class)->make();
        // Go through registration process
        $this->visit('/register')
            ->type($user->name, '#name')
            ->type($user->email, '#email')
            ->type($user->password, '#password')
            ->press('Create Account')
            ->seePageIs('/register')
            ->dontSeeInDatabase('users', ['email' => $user->email])
            ->see('That email domain does not have access to this application');

        $user->email = 'barry@example.com';

        $this->visit('/register')
            ->type($user->name, '#name')
            ->type($user->email, '#email')
            ->type($user->password, '#password')
            ->press('Create Account')
            ->seePageIs('/register/confirm')
            ->seeInDatabase('users', ['name' => $user->name, 'email' => $user->email, 'email_confirmed' => false]);

        $this->visit('/')->seePageIs('/login')
            ->type($user->email, '#email')
            ->type($user->password, '#password')
            ->press('Log In')
            ->seePageIs('/register/confirm/awaiting')
            ->seeText('Email Address Not Confirmed');
    }

    // 測試用管理員帳號登入後，新增一個使用者
    public function test_user_creation()
    {
        $user = factory(User::class)->make();

        $this->asAdmin()
            ->visit('/settings/users')
            ->click('Add New User')
            ->type($user->name, '#name')
            ->type($user->email, '#email')
            ->check('roles[admin]')
            ->type($user->password, '#password')
            ->type($user->password, '#password-confirm')
            ->press('Save')
            ->seePageIs('/settings/users')
            ->seeInDatabase('users', $user->toArray())
            ->see($user->name);
    }

    // 測試用管理員帳號登入後，修改一個普通使用者的姓名
    public function test_user_updating()
    {
        $user = $this->getNormalUser();
        $password = $user->password;
        $this->asAdmin()
            ->visit('/settings/users')
            ->click($user->name)
            ->seePageIs('/settings/users/' . $user->id)
            ->see($user->email)
            ->type('Barry Scott', '#name')
            ->press('Save')
            ->seePageIs('/settings/users')
            ->seeInDatabase('users', ['id' => $user->id, 'name' => 'Barry Scott', 'password' => $password])
            ->notSeeInDatabase('users', ['name' => $user->name]);
    }

    // 測試使用者修改密碼
    public function test_user_password_update()
    {
        $user = $this->getNormalUser();
        $userProfilePage = '/settings/users/' . $user->id;
        $this->asAdmin()
            ->visit($userProfilePage)
            ->type('newpassword', '#password')
            ->press('Save')
            ->seePageIs($userProfilePage)
            ->see('Password confirmation required')

            ->type('newpassword', '#password')
            ->type('newpassword', '#password-confirm')
            ->press('Save')
            ->seePageIs('/settings/users');

            $userPassword = User::find($user->id)->password;
            $this->assertTrue(Hash::check('newpassword', $userPassword));
    }

    // 測試管理刪除使用者
    public function test_user_deletion()
    {
        $userDetails = factory(User::class)->make();
        $user = $this->getEditor($userDetails->toArray());

        $this->asAdmin()
            ->visit('/settings/users/' . $user->id)
            ->click('Delete User')
            ->see($user->name)
            ->press('Confirm')
            ->seePageIs('/settings/users')
            ->notSeeInDatabase('users', ['name' => $user->name]);
    }

    // 只剩最後一個管理員帳號時，不能刪除它
    public function test_user_cannot_be_deleted_if_last_admin()
    {
        $adminRole = Role::getRole('admin');

        // Delete all but one admin user if there are more than one
        $adminUsers = $adminRole->users;
        if (count($adminUsers) > 1) {
            foreach ($adminUsers->splice(1) as $user) {
                $user->delete();
            }
        }

        // Ensure we currently only have 1 admin user
        $this->assertEquals(1, $adminRole->users()->count());
        $user = $adminRole->users->first();

        $this->asAdmin()->visit('/settings/users/' . $user->id)
            ->click('Delete User')
            ->press('Confirm')
            ->seePageIs('/settings/users/' . $user->id)
            ->see('You cannot delete the only admin');
    }

    // 測試登出
    public function test_logout()
    {
        $this->asAdmin()
            ->visit('/')
            ->seePageIs('/')
            ->visit('/logout')
            ->visit('/')
            ->seePageIs('/login');
    }

    // 測試重設密碼流程
    public function test_reset_password_flow()
    {
        Notification::fake();

        $this->visit('/login')->click('Forgot Password?')
            ->seePageIs('/password/email')
            ->type('admin@admin.com', 'email')
            ->press('Send Reset Link')
            ->see('A password reset link will be sent to admin@admin.com if that email address is found in the system.');

        $this->seeInDatabase('password_resets', [
            'email' => 'admin@admin.com'
        ]);

        $user = User::where('email', '=', 'admin@admin.com')->first();

        Notification::assertSentTo($user, ResetPassword::class);
        $n = Notification::sent($user, ResetPassword::class);

        $this->visit('/password/reset/' . $n->first()->token)
            ->see('Reset Password')
            ->submitForm('Reset Password', [
                'email' => 'admin@admin.com',
                'password' => 'randompass',
                'password_confirmation' => 'randompass'
            ])->seePageIs('/')
            ->see('Your password has been successfully reset');
    }

    public function test_reset_password_flow_shows_success_message_even_if_wrong_password_to_prevent_user_discovery()
    {
        $this->visit('/login')->click('Forgot Password?')
            ->seePageIs('/password/email')
            ->type('barry@admin.com', 'email')
            ->press('Send Reset Link')
            ->see('A password reset link will be sent to barry@admin.com if that email address is found in the system.')
            ->dontSee('We can\'t find a user');


        $this->visit('/password/reset/arandometokenvalue')
            ->see('Reset Password')
            ->submitForm('Reset Password', [
                'email' => 'barry@admin.com',
                'password' => 'randompass',
                'password_confirmation' => 'randompass'
            ])->followRedirects()
            ->seePageIs('/password/reset/arandometokenvalue')
            ->dontSee('We can\'t find a user')
            ->see('The password reset token is invalid for this email address.');
    }

    public function test_reset_password_page_shows_sign_links()
    {
        $this->setSettings(['registration-enabled' => 'true']);
        $this->visit('/password/email')
            ->seeLink('Log in')
            ->seeLink('Sign up');
    }

    public function test_login_redirects_to_initially_requested_url_correctly()
    {
        config()->set('app.url', 'http://localhost');
        $page = Page::query()->first();

        $this->visit($page->getUrl())
            ->seePageUrlIs(url('/login'));
        $this->login('admin@admin.com', 'password')
            ->seePageUrlIs($page->getUrl());
    }

    public function test_login_authenticates_admins_on_all_guards()
    {
        $this->post('/login', ['email' => 'admin@admin.com', 'password' => 'password']);
        $this->assertTrue(auth()->check());
        $this->assertTrue(auth('ldap')->check());
        $this->assertTrue(auth('saml2')->check());
    }

    public function test_login_authenticates_nonadmins_on_default_guard_only()
    {
        $editor = $this->getEditor();
        $editor->password = bcrypt('password');
        $editor->save();

        $this->post('/login', ['email' => $editor->email, 'password' => 'password']);
        $this->assertTrue(auth()->check());
        $this->assertFalse(auth('ldap')->check());
        $this->assertFalse(auth('saml2')->check());
    }

    /**
     * Perform a login
     */
    protected function login(string $email, string $password): AuthTest
    {
        return $this->visit('/login')
            ->type($email, '#email')
            ->type($password, '#password')
            ->press('Log In');
    }
}
