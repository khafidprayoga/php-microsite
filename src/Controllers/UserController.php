<?php

namespace Khafidprayoga\PhpMicrosite\Controllers;

use Carbon\Carbon;
use Khafidprayoga\PhpMicrosite\Commons\HttpException;
use Khafidprayoga\PhpMicrosite\Models\DTO\LoginRequestDTO;
use Khafidprayoga\PhpMicrosite\Models\DTO\RefreshSessionRequestDTO;
use Khafidprayoga\PhpMicrosite\Models\DTO\UserDTO;
use Khafidprayoga\PhpMicrosite\Utils\Cookies;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

class UserController extends InitController
{
    public function actionCreateUser(ServerRequestInterface $request): void
    {
        try {
            $data = $this->getFormData($request);
            $userRegister = new UserDTO($data);

            // creating user
            $this->userService->createUser($userRegister);

            $credentials = $this->authService->login($userRegister->username, $userRegister->password);
            setcookie("accessToken", $credentials->getAccessToken(), Cookies::formatSettings(
                appConfig: APP_CONFIG,
                expiresIn: $credentials->getAccessTokenExpiresAt(),
                path: '/',
            ));

            setcookie("refreshToken", $credentials->getRefreshToken(), Cookies::formatSettings(
                appConfig: APP_CONFIG,
                expiresIn: $credentials->getRefreshTokenExpiresAt(),
                path: '/',
            ));

            $this->redirectToFeeds();
        } catch (HttpException $err) {
            $this->responseJson($err, null, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function signIn(ServerRequestInterface $request): void
    {
        // check token
        $isAuthenticated = $this->authCheck($request);
        if ($isAuthenticated) {
            $this->redirectToFeeds();
        }

        // if user doesnt has token or token expired render login page
        $this->render('User/SignIn', [
            'actionUrl' => '/users/login',
            'httpMethod' => 'POST',
        ]);
    }

    public
    function signUp(ServerRequestInterface $request): void
    {
        // check token
        $isAuthenticated = $this->authCheck($request);
        if ($isAuthenticated) {
            $this->redirectToFeeds();
        }

        // if error instance of claims validation force render the template
        $this->render('User/SignUp', [
            'actionUrl' => '/users/register',
            'httpMethod' => 'POST',
        ]);
    }

    public
    function actionAuthenticate(ServerRequestInterface $request): void
    {
        try {
            $formData = $this->getFormData($request);
            $loginRequest = new LoginRequestDTO($formData);

            $credentials = $this->authService->login($loginRequest->getUsername(), $loginRequest->getPassword());
            setcookie("accessToken", $credentials->getAccessToken(), Cookies::formatSettings(
                appConfig: APP_CONFIG,
                expiresIn: $credentials->getAccessTokenExpiresAt(),
                path: '/',
            ));

            setcookie("refreshToken", $credentials->getRefreshToken(), Cookies::formatSettings(
                appConfig: APP_CONFIG,
                expiresIn: $credentials->getRefreshTokenExpiresAt(),
                path: '/',
            ));

            $this->redirectToFeeds();
        } catch (HttpException $err) {
            $this->responseJson($err, null, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function actionLogout(): void
    {
        // check token
        $accessToken = $_COOKIE['accessToken'] ?? null;
        $refreshToken = $_COOKIE['refreshToken'] ?? null;
        if (is_null($accessToken) && is_null($refreshToken)) {
            $this->redirectToLogin();
        }

        // flush cookies
        $cookiesKey = ['accessToken', 'refreshToken'];
        foreach ($cookiesKey as $key) {
            if ($key === 'refreshToken') {
                $this->authService->logout($_COOKIE["refreshToken"]);
            }

            // set expiring on browser
            setcookie($key, '', Cookies::formatSettings(
                appConfig: APP_CONFIG,
                expiresIn: Carbon::now()->addDays(-30)->timestamp,
                path: '/',
            ));

            // flush on server
            unset($_COOKIE[$key]);
        }

        // redirect to signin page
        $this->redirectToLogin();
    }

    public function actionRevalidateToken(ServerRequestInterface $req): void
    {
        try {
            $jsonBody = $this->getJsonBody($req);
            $request = new RefreshSessionRequestDTO($jsonBody);

            $token = $this->authService->refresh($request);
            $this->responseJson(null, $token, Response::HTTP_OK);
        } catch (HttpException $err) {
            $this->responseJson($err, null, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function redirectToFeeds(): void
    {
        //redirect to feeds
        // Set the HTTP status code to 302 (temporary redirect)
        http_response_code(302);

        // Set the Location header to the login page URL
        header('Location: /feeds');
        exit(0);
    }

    private function redirectToLogin(): void
    {
        //redirect to feeds
        // Set the HTTP status code to 302 (temporary redirect)
        http_response_code(302);

        // Set the Location header to the login page URL
        header('Location: /signin');
        exit(0);
    }


    private function authCheck(ServerRequestInterface $request): bool
    {
        // check token
        $accessToken = $_COOKIE['accessToken'] ?? null;
        $refreshToken = $_COOKIE['refreshToken'] ?? null;

        if (!$accessToken || !$refreshToken) {
            return false;
        }
        // decode jwt and return json err on response at server
        try {
            $claims = $this->authService->validate($accessToken);

            if ($claims->getUserId() > 0) {
                return true;
            }
        } catch (HttpException) {
            try {
                // regenerate access token
                $newAccessToken = $this->authService->refresh($refreshToken);

                // set new cookies for the accessToken
                setCookie('accessToken', $newAccessToken, Cookies::formatSettings(
                    appConfig: APP_CONFIG,
                    expiresIn: $newAccessToken->getAccessTokenExpiresAt(),
                    path: '/',
                ));

                return true;
            } catch (HttpException) {
                return false;
            }
        }
        return false;
    }
}
