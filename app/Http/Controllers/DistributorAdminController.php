<?php

namespace App\Http\Controllers;

use App\Models\Distributor;
use App\Services\Feeds\Contracts\FeedDriverInterface;
use App\Services\Feeds\FeedIngestionService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class DistributorAdminController extends Controller
{
    /**
     * Connection-settings keys surfaced on the edit form, by transport.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSPORT_FIELDS = [
        'sftp' => ['host', 'port', 'username', 'password', 'remote_path'],
        'ftp' => ['host', 'port', 'username', 'password', 'remote_path'],
        'http_csv' => ['feed_url', 'token'],
        'rest_api' => ['base_uri', 'api_key'],
    ];

    /** Fields that hold secrets and are never rendered back to the browser. */
    public const SECRET_FIELDS = ['password', 'token', 'api_key'];

    private const FIELD_LABELS = [
        'host' => 'Host',
        'port' => 'Port',
        'username' => 'Username',
        'password' => 'Password',
        'remote_path' => 'Remote Path',
        'feed_url' => 'Feed URL',
        'token' => 'Token',
        'base_uri' => 'Base URL',
        'api_key' => 'API Key',
    ];

    public function edit(Distributor $distributor)
    {
        $fields = $this->fieldsFor($distributor);

        return view('distributors.edit', [
            'distributor' => $distributor,
            'fields' => $fields,
            'fieldLabels' => self::FIELD_LABELS,
            'secretFields' => self::SECRET_FIELDS,
            'settings' => (array) ($distributor->connection_settings ?? []),
            'frequencies' => (array) config('feed.sync_frequencies', ['hourly', 'daily', 'manual']),
        ]);
    }

    public function update(Request $request, Distributor $distributor)
    {
        $fields = $this->fieldsFor($distributor);

        $rules = [
            'is_active' => ['sometimes', 'boolean'],
            'sync_frequency' => ['required', 'string', 'max:40'],
        ];
        foreach ($fields as $field) {
            $rules["settings.$field"] = ['nullable', 'string', 'max:4096'];
        }

        $validated = $request->validate($rules);

        $incoming = Arr::only($validated['settings'] ?? [], $fields);
        $existing = (array) ($distributor->connection_settings ?? []);
        $merged = $existing;

        foreach ($fields as $field) {
            $value = $incoming[$field] ?? null;
            $isBlank = $value === null || $value === '';

            // A blank secret means "keep whatever is stored".
            if ($isBlank && in_array($field, self::SECRET_FIELDS, true)) {
                continue;
            }

            if ($field === 'port') {
                $merged[$field] = $isBlank ? null : (int) $value;

                continue;
            }

            $merged[$field] = $isBlank ? null : $value;
        }

        $distributor->fill([
            'connection_settings' => $merged,
            'is_active' => $request->boolean('is_active'),
            'sync_frequency' => $validated['sync_frequency'],
        ])->save();

        return redirect()
            ->route('distributors.edit', $distributor)
            ->with('success', "Saved connection settings for {$distributor->name}.");
    }

    public function testConnection(Distributor $distributor)
    {
        try {
            $ok = $this->resolveDriver($distributor)->testConnection($distributor);
        } catch (\Throwable $e) {
            return back()->with('error', "Connection test for {$distributor->name} errored: {$e->getMessage()}");
        }

        return $ok
            ? back()->with('success', "Connection to {$distributor->name} succeeded.")
            : back()->with('warning', "Connection to {$distributor->name} failed — verify the host and credentials.");
    }

    public function manualSync(Distributor $distributor, FeedIngestionService $ingestionService)
    {
        try {
            $run = $ingestionService->ingest($distributor);
        } catch (\Throwable $e) {
            return redirect()
                ->route('supply.distributors')
                ->with('error', "Sync for {$distributor->name} threw an exception: {$e->getMessage()}");
        }

        $summary = sprintf(
            '%s: %s processed, %s updated, %s failed',
            $distributor->name,
            number_format((int) $run->rows_processed),
            number_format((int) $run->rows_updated),
            number_format((int) $run->rows_failed),
        );

        if ($run->status === 'completed') {
            return redirect()->route('supply.distributors')->with('success', "Sync complete — {$summary}.");
        }

        $detail = $run->error_message ? " ({$run->error_message})" : '';

        return redirect()
            ->route('supply.distributors')
            ->with('error', "Sync {$run->status} — {$summary}.{$detail}");
    }

    /**
     * @return array<int, string>
     */
    private function fieldsFor(Distributor $distributor): array
    {
        return self::TRANSPORT_FIELDS[$distributor->transport_type] ?? self::TRANSPORT_FIELDS['sftp'];
    }

    private function resolveDriver(Distributor $distributor): FeedDriverInterface
    {
        $class = $distributor->driver_class;

        abort_unless($class && class_exists($class), 422, "Feed driver [{$class}] is not available.");

        $driver = app($class);

        abort_unless($driver instanceof FeedDriverInterface, 422, "Feed driver [{$class}] is invalid.");

        return $driver;
    }
}
