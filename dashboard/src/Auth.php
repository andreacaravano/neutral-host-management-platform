<?php

namespace NHMP;

use PDO;

final class Auth
{
    public static function login(string $email, string $password): bool
    {
        if (defined("DEMO") && DEMO) {
            return true;
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT id, name, surname, email FROM \"User\" WHERE email = :email AND encode(digest(salt || :password, 'sha256'), 'hex') = SHAPassword LIMIT 1",
        );

        $stmt->execute(["email" => $email, "password" => $password]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return false;
        }

        $_SESSION["user_id"] = $user["id"];
        $_SESSION["user_name"] = $user["name"];
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                "",
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"],
            );
        }
        session_destroy();
    }

    public static function user(): ?array
    {
        $pdo = Database::pdo();

        if (defined("DEMO") && DEMO) {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare(
                'SELECT id, name, surname, email FROM "User" WHERE email = \'demo@polimi.it\' LIMIT 1',
            );
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $_SESSION["user_id"] = $user["id"];
                return $user;
            }
        }

        if (!empty($_SESSION["user_id"])) {
            $stmt = $pdo->prepare(
                'SELECT id, name, surname, email FROM "User" WHERE id = :id LIMIT 1',
            );
            $stmt->execute(["id" => $_SESSION["user_id"]]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                return $user;
            }
        }

        if (Config::demoMode()) {
            $user = $pdo
                ->query(
                    'SELECT id, name, surname, email FROM "User" ORDER BY email ASC LIMIT 1',
                )
                ->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $_SESSION["user_id"] = $user["id"];
                return $user;
            }
        }

        return null;
    }

    public static function requireUser(): array
    {
        $user = self::user();
        if (!$user) {
            http_response_code(401);
            header("Content-Type: application/json; charset=utf-8");
            echo json_encode(["error" => "unauthorized"]);
            exit();
        }
        return $user;
    }
}
