<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Illuminate\Console\Command;
use JsonException;
use Mk\Director\Spec\DataCheck;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mk-studio:data-check '<json>'` — what MK Studio's «Revisar contra la app» counts in the app's data, as JSON on
 * stdout (one line). Read-only. The request goes as an argument because the studio's artisan runner has no stdin;
 * what it asks is what the screen shows, a few KB. See {@see DataCheck} for the request and the answer.
 */
final class SpecDataCheckCommand extends Command
{
    protected $signature = 'mk-studio:data-check {request : The request as JSON (relations, columns, values, migrations)}';

    protected $description = 'Counts what MK Studio asks of the app\'s data (orphans, values a drop erases, rows of an enum case, migrations run) as JSON.';

    public function handle(): int
    {
        try {
            $request = json_decode((string) $this->argument('request'), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error("The request is not JSON: {$e->getMessage()}");

            return self::INVALID;
        }
        if (! is_array($request) || array_is_list($request) && $request !== []) {
            $this->error('The request is a JSON object: {"relations": [...], "columns": [...], "values": [...], "migrations": true}.');

            return self::INVALID;
        }
        foreach (['relations', 'columns', 'values'] as $list) {
            if (isset($request[$list]) && (! is_array($request[$list]) || ! array_is_list($request[$list]) || array_filter($request[$list], 'is_array') !== $request[$list])) {
                $this->error("\"{$list}\" is a list of objects.");

                return self::INVALID;
            }
        }

        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
        $this->output->writeln(json_encode((new DataCheck)->run($request), $flags), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
