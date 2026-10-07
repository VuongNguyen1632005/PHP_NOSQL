<?php
declare(strict_types=1);

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money(mixed $value): string
{
    return number_format((float) $value, 0, ',', '.') . '₫';
}

/** @param array<string, mixed> $product */
function productImage(array $product): string
{
    return (string) ($product['images'][0]['path'] ?? 'assets/img/resourse/product-placeholder.png');
}

function csrfToken(): string
{
    return \App\Core\Csrf::token();
}

/** @return array<string, mixed>|null */
function currentUser(): ?array
{
    $user = $_SESSION['auth_user'] ?? null;
    return is_array($user) ? $user : null;
}

function cartLineCount(): int
{
    if ((currentUser()['type'] ?? null) === 'customer') {
        return max(0, (int) ($_SESSION['_cart_line_count'] ?? 0));
    }
    $cart = $_SESSION['guest_cart'] ?? [];
    return is_array($cart) ? count($cart) : 0;
}
