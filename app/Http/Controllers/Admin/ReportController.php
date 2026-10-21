<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BusinessHours;
use App\Services\RevenueReport;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function daily(Request $request, BusinessHours $hours, RevenueReport $report)
    {
        $date = $hours->parseDate($request->query('date')) ?? $hours->now()->startOfDay();

        return view('admin.report', ['report' => $report->forDate($date->format('Y-m-d'))]);
    }
}
