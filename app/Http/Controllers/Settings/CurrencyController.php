<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\ExchangeRates;
use App\Support\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The header currency switch for Admin/Staff users (routes in routes/settings.php, behind
 * admin_or_staff). Only the display preference is saved; stored amounts are never changed.
 */
class CurrencyController extends Controller
{
    /** Save the signed-in user's display currency. */
    public function update(Request $request): JsonResponse
    {
        // Validated by hand: this app turns validation errors into redirects for non-/api URLs.
        $validator = Validator::make($request->all(), [
            'currency' => ['required', 'string', Rule::in(Currency::SUPPORTED)],
        ], ['currency.in' => 'Choose USD or PHP.']);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $user->forceFill(['preferred_currency' => $validator->validated()['currency']])->save();

        return response()->json(['preferred' => $user->preferred_currency]);
    }

    /** The USD→PHP rate for converting amounts on screen (`?refresh=1` for "Retry"). */
    public function rate(Request $request, ExchangeRates $rates): JsonResponse
    {
        $result = $rates->usdToPhp(refresh: $request->boolean('refresh'));

        return response()->json($result, $result['status'] === 'ok' ? 200 : 503);
    }
}
