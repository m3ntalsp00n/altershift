<?php
// Application middleware

use Slim\Middleware\JwtAuthentication;
use Response\UnauthorizedResponse;
use Models\Cache;

$keys_file = "securetoken.json"; // the file for the downloaded public keys

/**
 * Retrieves the downloaded keys.
 * This should be called anytime you need the keys (i.e. for decoding / verification).
 * @return null|string
 */
function getKeys()
{
    $cache = Cache::get()->first();
    if ($cache) {
        return $cache->keys;
    }
    return null;
}

$container["jwt"] = function ($container) {
    return new StdClass;
};
// e.g: $app->add(new \Slim\Csrf\Guard);
$container["JwtAuthentication"] = function ($container) {
    $pkeys = json_decode(getKeys(), true);

    return new JwtAuthentication([
        "alogorithm" => ["RS256"],
        "path" => "/api",
        "ignore" => ["/token", "/register"],
        "secure" => false,
        // "secret" => "supersecretkeyyoushouldnotcommittogithub", //getenv("JWT_SECRET"),
        // google public key
        "secret" => $pkeys,
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