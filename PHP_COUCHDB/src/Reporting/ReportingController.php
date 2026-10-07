<?php
declare(strict_types=1);

namespace App\Reporting;

use App\Core\Response;
use App\Core\View;

final class ReportingController
{
    public function __construct(private readonly RevenueReportService $reports)
    {
    }

    /** @param array<string, string> $params
     *  @param array<string, mixed> $query
     */
    public function revenue(array $params, array $query): Response
    {
        if (($guard = $this->staffGuard()) !== null) return $guard;
        $report = $this->reports->report();
        return new Response(View::render('admin/revenue/index', ['title' => 'Báo cáo doanh thu', 'report' => $report]));
    }

    /** @param array<string, string> $params
     *  @param array<string, mixed> $query
     */
    public function stats(array $params, array $query): Response
    {
        if (($guard = $this->staffGuard()) !== null) return $guard;
        return new Response(
            json_encode($this->reports->report(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            200,
            'application/json; charset=utf-8',
        );
    }

    private function staffGuard(): ?Response
    {
        $user = $_SESSION['auth_user'] ?? null;
        if (!is_array($user)) return new Response('', 302, 'text/html; charset=utf-8', ['Location' => '/login']);
        if (($user['type'] ?? null) !== 'staff' || !in_array(($user['role'] ?? null), ['manager', 'staff'], true)) {
            return new Response('<h1>403 — Không có quyền truy cập</h1><p><a href="/">Về trang chủ</a></p>', 403);
        }
        return null;
    }
}
