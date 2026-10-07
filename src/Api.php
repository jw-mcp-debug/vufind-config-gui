<?php

declare(strict_types=1);

namespace VuFindConfigGui;

use VuFindConfigGui\Mcp\McpClient;
use VuFindConfigGui\Mcp\McpConfig;

/**
 * JSON API used by the browser. Every action returns an array; errors are
 * thrown as HttpException with a translation key.
 */
final class Api
{
    private readonly FileStore $files;
    private readonly Solr $solr;

    public function __construct(
        private readonly Settings $settings,
        private readonly Instance $inst,
        private readonly I18n $i18n,
    ) {
        $this->files = new FileStore($inst);
        $this->solr = new Solr($inst->solr);
    }

    public function handle(string $action, array $query, array $body): array
    {
        $method = 'action' . ucfirst($action);
        if (!preg_match('/^[a-zA-Z]+$/', $action) || !method_exists($this, $method)) {
            throw new HttpException('error.unknown_action', [], 404);
        }
        return $this->$method($query, $body);
    }

    // ------------------------------------------------------------ files

    private function actionFiles(): array
    {
        $instances = [];
        foreach ($this->settings->instances as $i) {
            $instances[] = ['key' => $i->key, 'label' => $i->label, 'url' => $i->url, 'hasMcp' => $i->mcp !== null];
        }
        return [
            'files' => array_map([$this->files, 'info'], array_keys($this->files->curated())),
            'others' => array_map([$this->files, 'info'], $this->files->otherFiles()),
            'vufindUrl' => $this->inst->url,
            'instance' => $this->inst->key,
            'instances' => $instances,
            'hasSolr' => $this->solr->available(),
            'mcp' => $this->inst->mcp ? ['publicUrl' => $this->inst->mcp['public_url'], 'parkedKey' => $this->inst->mcp['parked_key']] : null,
            'problems' => array_map(fn ($p) => $this->i18n->t($p[0], $p[1]), $this->inst->problems()),
        ];
    }

    private function actionGet(array $query): array
    {
        $rel = FileStore::checkRel((string)($query['file'] ?? ''));
        $type = FileStore::type($rel);
        $info = $this->files->info($rel);
        if (!$info['hasLocal'] && !$info['hasOrig']) {
            throw new HttpException('error.file_not_found', ['file' => $rel], 404);
        }
        $path = $this->files->effectivePath($rel);
        $out = ['info' => $info, 'raw' => (string)file_get_contents($path), 'mtime' => $this->files->mtime($rel)];
        if ($type !== 'yaml') {
            $sections = LineConfig::parse(LineConfig::readLines($path), $type);
            $orig = LineConfig::valueMap(LineConfig::parse(LineConfig::readLines($this->inst->origPath($rel)), $type));
            foreach ($sections as &$s) {
                $count = [];
                foreach ($s['entries'] as &$e) {
                    $n = $count[$e['key']] = ($count[$e['key']] ?? 0) + 1;
                    $o = $orig[LineConfig::mapKey($s['name'], $e['key'], $n)] ?? null;
                    $e['orig'] = $o ? ['value' => $o['value'], 'active' => $o['active']] : null;
                    if ($e['help'] === '' && $o) {
                        // The local copy may lack the comments; take the help text from the original
                        $e['help'] = $o['help'];
                    }
                }
            }
            $out['sections'] = $sections;
        }
        return $out;
    }

    /** Structured changes: [{line, key, value, active}] */
    private function actionSave(array $query, array $body): array
    {
        $rel = FileStore::checkRel((string)($body['file'] ?? ''));
        $type = FileStore::type($rel);
        if ($type === 'yaml') {
            throw new HttpException('error.yaml_raw_only');
        }
        $this->files->assertUnchanged($rel, $body['mtime'] ?? 0);
        $changes = array_values(array_filter((array)($body['changes'] ?? []), 'is_array'));
        $path = $this->files->effectivePath($rel);
        $raw = is_file($path) ? (string)file_get_contents($path) : '';
        $eol = str_contains($raw, "\r\n") ? "\r\n" : "\n";
        $lines = LineConfig::applyChanges(LineConfig::readLines($path), $changes, $type);
        $new = implode($eol, $lines);
        $this->assertValidIni($rel, $new);
        $created = !is_file($this->inst->localPath($rel));
        $backup = $this->files->backup($rel);
        $this->files->write($rel, $new);
        return ['ok' => true, 'applied' => count($changes), 'backup' => $backup, 'createdLocal' => $created,
            'cache' => $this->files->clearCaches()];
    }

    private function actionSaveRaw(array $query, array $body): array
    {
        $rel = FileStore::checkRel((string)($body['file'] ?? ''));
        $raw = (string)($body['raw'] ?? '');
        $this->files->assertUnchanged($rel, $body['mtime'] ?? 0);
        if (FileStore::type($rel) === 'yaml') {
            Yaml::init($this->inst);
            Yaml::parse($raw);
        }
        $this->assertValidIni($rel, $raw);
        $created = !is_file($this->inst->localPath($rel));
        $backup = $this->files->backup($rel);
        $this->files->write($rel, $raw);
        return ['ok' => true, 'backup' => $backup, 'createdLocal' => $created, 'cache' => $this->files->clearCaches()];
    }

    private function actionCreateLocal(array $query, array $body): array
    {
        $rel = FileStore::checkRel((string)($body['file'] ?? ''));
        $this->files->ensureLocal($rel);
        return ['ok' => true];
    }

    private function actionDeleteLocal(array $query, array $body): array
    {
        $rel = FileStore::checkRel((string)($body['file'] ?? ''));
        if (in_array($rel, $this->inst->protectedFiles, true)) {
            throw new HttpException('error.protected');
        }
        $backup = $this->files->backup($rel);
        $this->files->deleteLocal($rel);
        return ['ok' => true, 'backup' => $backup, 'cache' => $this->files->clearCaches()];
    }

    private function actionDiff(array $query): array
    {
        $diff = $this->files->diff(FileStore::checkRel((string)($query['file'] ?? '')));
        if ($diff === null) {
            throw new HttpException('error.no_diff', [], 500);
        }
        return ['diff' => $diff];
    }

    private function actionClearCache(): array
    {
        return ['ok' => true, 'cache' => $this->files->clearCaches()];
    }

    private function actionSearchAll(array $query): array
    {
        return (new SettingsSearch($this->files))->search((string)($query['q'] ?? ''));
    }

    /** Refuse ini content PHP (and thus VuFind) cannot read */
    private function assertValidIni(string $rel, string $raw): void
    {
        if (FileStore::type($rel) !== 'ini') {
            return;
        }
        $err = '';
        set_error_handler(function (int $no, string $msg) use (&$err) {
            $err = $msg;
            return true;
        });
        try {
            $ok = parse_ini_string($raw, true, INI_SCANNER_NORMAL) !== false;
        } finally {
            restore_error_handler();
        }
        if (!$ok) {
            throw new HttpException('error.ini_invalid', ['message' => $err]);
        }
    }

    // ------------------------------------------------------------ Solr / ranking

    private function actionSolrFilter(array $query, array $body): array
    {
        return $this->solr->filterCount((string)($body['fq'] ?? ''));
    }

    private function actionSpecModel(): array
    {
        Yaml::init($this->inst);
        $orig = Yaml::parseFile($this->inst->origPath(Ranking::FILE));
        $local = null;
        $foreignComments = false;
        $localPath = $this->inst->localPath(Ranking::FILE);
        if (is_file($localPath)) {
            $raw = (string)file_get_contents($localPath);
            $local = Yaml::parse($raw) ?? [];
            $foreignComments = Ranking::hasForeignComments($raw);
        }
        return ['orig' => $orig, 'local' => $local, 'localComments' => $foreignComments,
            'directives' => array_values(array_intersect(Ranking::DIRECTIVES, array_keys($local ?? []))),
            'solr' => $this->solr->available() ? $this->solr->fieldStats() : ['total' => 0, 'fields' => []],
            'info' => $this->files->info(Ranking::FILE), 'mtime' => $this->files->mtime(Ranking::FILE)];
    }

    /** Only search types that differ: VuFind inherits missing sections from the original */
    private function actionSpecSave(array $query, array $body): array
    {
        $overrides = $body['overrides'] ?? null;
        if (!is_array($overrides)) {
            throw new HttpException('error.no_changes');
        }
        $this->files->assertUnchanged(Ranking::FILE, $body['mtime'] ?? 0);
        Yaml::init($this->inst);
        $backup = $this->files->backup(Ranking::FILE);
        if (!$overrides) {
            // Nothing differs any more: remove the local copy, the original applies again
            $this->files->deleteLocal(Ranking::FILE);
            return ['ok' => true, 'backup' => $backup, 'removedLocal' => true, 'cache' => $this->files->clearCaches()];
        }
        $yaml = Ranking::header() . Yaml::dump($overrides, 6);
        Yaml::parse($yaml);
        $this->files->write(Ranking::FILE, $yaml);
        return ['ok' => true, 'backup' => $backup, 'cache' => $this->files->clearCaches()];
    }

    private function actionSpecPreview(array $query, array $body): array
    {
        $arr = fn ($k) => is_array($body[$k] ?? null) ? $body[$k] : [];
        [$params, $note] = Ranking::solrParams($arr('spec'), (string)($body['q'] ?? ''), (string)($body['type'] ?? ''),
            $arr('global'), $arr('all'));
        if ($params === null) {
            return ['unsupported' => $this->i18n->t($note)];
        }
        return $this->solr->query($params) + [
            'note' => $note ? $this->i18n->t($note) : '',
            'params' => array_values(array_filter($params, fn ($p) => !in_array($p[0], ['fl', 'rows'], true))),
        ];
    }

    // ------------------------------------------------------------ MCP (experimental, optional)

    private function mcpConfig(): McpConfig
    {
        if ($this->inst->mcp === null) {
            throw new HttpException('error.no_mcp');
        }
        return new McpConfig($this->inst, $this->files);
    }

    private function actionMcpModel(): array
    {
        $mcp = $this->mcpConfig();
        return ['model' => $mcp->load(), 'fields' => $mcp->availableFields(),
            'formats' => $this->solr->available() ? $this->solr->formats() : [],
            'info' => $this->files->info(McpConfig::FILE), 'mtime' => $this->files->mtime(McpConfig::FILE)];
    }

    private function actionMcpSave(array $query, array $body): array
    {
        $mcp = $this->mcpConfig();
        if (!is_array($body['model'] ?? null)) {
            throw new HttpException('error.no_model');
        }
        $this->files->assertUnchanged(McpConfig::FILE, $body['mtime'] ?? 0);
        $yaml = $mcp->render($body['model']);
        $backup = $this->files->backup(McpConfig::FILE);
        $this->files->write(McpConfig::FILE, $yaml);
        return ['ok' => true, 'backup' => $backup, 'cache' => $this->files->clearCaches()];
    }

    private function actionMcpRpc(array $query, array $body): array
    {
        $this->mcpConfig();
        $client = new McpClient($this->inst->mcp['endpoint'], $this->inst->mcp['public_url']);
        $r = $client->call((string)($body['method'] ?? ''), is_array($body['params'] ?? null) ? $body['params'] : []);
        if (!$r['ok']) {
            $e = $r['error'];
            $r['error'] = $this->i18n->t($e['key'], ['status' => $e['status']]) . ($e['hint'] ? ' ' . $this->i18n->t($e['hint']) : '');
        }
        return $r;
    }
}
