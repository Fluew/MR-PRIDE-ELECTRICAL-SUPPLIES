<?php

namespace App\Http\Controllers;

use App\Exceptions\WorkflowException;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

abstract class Controller
{
    /**
     * Run a workflow action and go back with its success message, or with the business rule
     * that stopped it (nothing was saved in that case). Database errors are logged, never shown.
     *
     * @param  callable(): (string|null)  $action  returns the success message
     */
    protected function runWorkflow(callable $action): RedirectResponse
    {
        try {
            $message = $action();
        } catch (WorkflowException $e) {
            return back()->withErrors([$e->field => $e->getMessage()])->withInput();
        } catch (QueryException $e) {
            Log::error('Workflow action failed: '.$e->getMessage(), ['exception' => $e]);

            return back()->withErrors(['error' => 'The change could not be saved because of a database problem. Nothing was saved. Please try again.'])->withInput();
        }

        return back()->with('success', $message ?? 'Saved.');
    }
}
