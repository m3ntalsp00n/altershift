<?php
// Application middleware

use Slim\Middleware\JwtAuthentication;
use Response\UnauthorizedResponse;

$container["jwt"] = function ($container) {
    return new StdClass;
};
// e.g: $app->add(new \Slim\Csrf\Guard);
$container["JwtAuthentication"] = function ($container) {
    return new JwtAuthentication([
        "path" => "/api",
        "ignore" => ["/token", "/register"],
        "secure" => false,
        "secret" => "supersecretkeyyoushouldnotcommittogithub", //getenv("JWT_SECRET"),
        "logger" => $container["logger"],
        "attribute" => false,
        "relaxed" => ["192.168.166.134", "127.0.0.1", "localhost"],
        // "error" => function ($request, $response, $arguments) {
        //     return new UnauthorizedResponse($arguments["message"], 401);
        // },
        // "before" => function ($request, $response, $arguments) use ($container) {
        //    // $container["token"]->hydrate($arguments["decoded"]);
        // }
        "callback" => function ($request, $response, $arguments) use ($container) {
           $container["jwt"] = $arguments["decoded"];
        }
    ]);
};

$app->add("JwtAuthentication");

abstract class Roles {
    const Admin = 0;
    const Staff = 1;
    const NewClient = 2;
    const NormalUser = 3;
    const Inactive = 4;
}