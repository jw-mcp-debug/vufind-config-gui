<?php

/**
 * Unit tests for the MCP Search2 search capability.
 *
 * PHP version 8
 *
 * Copyright (C) jw-mcp-debug 2026.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, see
 * <https://www.gnu.org/licenses/>.
 *
 * @category VuFind
 * @package  Tests
 * @author   jw-mcp-debug <274549306+jw-mcp-debug@users.noreply.github.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org Main Page
 */

namespace VuFindTest\Mcp\Capabilities;

use PHPUnit\Framework\TestCase;
use VuFind\Config\ConfigManagerInterface;
use VuFind\Http\RouteHelper;
use VuFind\Http\ServerUrlHelper;
use VuFind\Record\Loader;
use VuFind\Search\Base\Results as BaseResults;
use VuFind\Search\SearchRunner;
use VuFindApi\Formatter\RecordFormatter;
use VuFindApi\Mcp\Capabilities\SearchSearch2;

/**
 * Unit tests for the MCP Search2 search capability.
 *
 * Everything else is inherited from AbstractSearch and covered by
 * SearchSolrTest; these tests check what SearchSearch2 changes.
 *
 * @category VuFind
 * @package  Tests
 * @author   jw-mcp-debug <274549306+jw-mcp-debug@users.noreply.github.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org Main Page
 */
class SearchSearch2Test extends TestCase
{
    /**
     * Default response fields of AbstractSearch.
     *
     * @var array
     */
    protected const DEFAULT_FIELDS = ['recordPageAbsoluteLink', 'title', 'authors'];

    /**
     * Stand-in for the contents of Search2ApiRecordFields.yaml.
     *
     * @var array
     */
    protected const SEARCH2_FIELD_CONFIG = ['title' => ['vufind.method' => 'getTitle']];

    /**
     * Build a SearchSearch2 instance.
     *
     * @param array            $config          ModelContextProtocol.yaml contents
     * @param ?SearchRunner    $searchRunner    Search runner
     * @param ?RecordFormatter $recordFormatter Record formatter
     * @param ?Loader          $recordLoader    Record loader
     * @param ?RouteHelper     $routeHelper     Route helper
     *
     * @return SearchSearch2
     */
    protected function getSearchSearch2(
        array $config = [],
        ?SearchRunner $searchRunner = null,
        ?RecordFormatter $recordFormatter = null,
        ?Loader $recordLoader = null,
        ?RouteHelper $routeHelper = null
    ): SearchSearch2 {
        // Only these two configs may be read; any other name fails the match.
        $configManager = $this->createMock(ConfigManagerInterface::class);
        $configManager->method('getConfigArray')->willReturnCallback(
            fn (string $configName): array => match ($configName) {
                'ModelContextProtocol' => $config,
                'Search2ApiRecordFields' => self::SEARCH2_FIELD_CONFIG,
            }
        );
        if (!$routeHelper) {
            $routeHelper = $this->createMock(RouteHelper::class);
            $routeHelper->method('getUrlFromRoute')->willReturn('/Search2/Results');
        }
        $serverUrlHelper = $this->createMock(ServerUrlHelper::class);
        $serverUrlHelper->method('getBaseUrl')->willReturn('https://library.example.org');

        return new SearchSearch2(
            $configManager,
            $recordLoader ?? $this->createStub(Loader::class),
            $recordFormatter ?? $this->createStub(RecordFormatter::class),
            $searchRunner ?? $this->createStub(SearchRunner::class),
            $routeHelper,
            $serverUrlHelper,
        );
    }

    /**
     * Test that searchRecords() searches the Search2 backend, links to the
     * Search2 result list and formats records with the Search2 field config.
     *
     * @return void
     */
    public function testSearchRecordsUsesSearch2BackendRouteAndFields(): void
    {
        $results = $this->createMock(BaseResults::class);
        $results->method('getResults')->willReturn(['recordA']);

        $searchRunner = $this->createMock(SearchRunner::class);
        $searchRunner->expects($this->once())
            ->method('run')
            ->with(['lookfor' => 'test keywords'], 'Search2', $this->isCallable())
            ->willReturn($results);

        $recordFormatter = $this->createMock(RecordFormatter::class);
        $recordFormatter->expects($this->once())
            ->method('format')
            ->with(['recordA'], self::DEFAULT_FIELDS, self::SEARCH2_FIELD_CONFIG)
            ->willReturn([['id' => 'recordA', 'title' => 'An Article']]);

        $routeHelper = $this->createMock(RouteHelper::class);
        $routeHelper->expects($this->once())
            ->method('getUrlFromRoute')
            ->with('search2-results', [], ['lookfor' => 'test keywords'])
            ->willReturn('/Search2/Results?lookfor=test+keywords');

        $search = $this->getSearchSearch2([], $searchRunner, $recordFormatter, null, $routeHelper);
        $this->assertSame(
            [
                'search_results' => [['id' => 'recordA', 'title' => 'An Article']],
                'search_results_page' => 'https://library.example.org/Search2/Results?lookfor=test+keywords',
            ],
            $search->searchRecords('test keywords')
        );
    }

    /**
     * Test that content types work as with SearchSolr, against the Search2 backend.
     *
     * @return void
     */
    public function testSearchRecordsWithContentTypeAddsConfiguredFilter(): void
    {
        $config = ['ContentTypes' => ['articles' => ['filter' => '(format:Article)']]];
        $results = $this->createMock(BaseResults::class);
        $results->method('getResults')->willReturn([]);

        $searchRunner = $this->createMock(SearchRunner::class);
        $searchRunner->expects($this->once())
            ->method('run')
            ->with(['lookfor' => 'keywords', 'filter' => '(format:Article)'], 'Search2', $this->isCallable())
            ->willReturn($results);

        $this->getSearchSearch2($config, $searchRunner)->searchRecords('keywords', 'articles');
    }

    /**
     * Test that getRecord() loads the record from the Search2 backend.
     *
     * @return void
     */
    public function testGetRecordLoadsFromSearch2Backend(): void
    {
        $record = new \stdClass();
        $recordLoader = $this->createMock(Loader::class);
        $recordLoader->expects($this->once())->method('load')->with('art1', 'Search2')->willReturn($record);

        $recordFormatter = $this->createMock(RecordFormatter::class);
        $recordFormatter->expects($this->once())
            ->method('format')
            ->with([$record], self::DEFAULT_FIELDS, self::SEARCH2_FIELD_CONFIG)
            ->willReturn([['id' => 'art1', 'title' => 'An Article']]);

        $search = $this->getSearchSearch2([], null, $recordFormatter, $recordLoader);
        $this->assertSame(['id' => 'art1', 'title' => 'An Article'], $search->getRecord('art1'));
    }
}
