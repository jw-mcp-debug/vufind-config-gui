<?php

/**
 * MCP search capability for the second Solr index (Search2).
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
 * @package  Mcp
 * @author   jw-mcp-debug <274549306+jw-mcp-debug@users.noreply.github.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development Wiki
 */

namespace VuFindApi\Mcp\Capabilities;

/**
 * MCP search capability for the second Solr index (Search2).
 *
 * Many libraries keep a second index next to the catalog, for example for
 * articles or a repository, and connect it as Search2. SearchSolr only ever
 * searches the main index, so an AI client would see part of the collection
 * and take it for all of it. This class reuses everything in AbstractSearch
 * and only selects the backend, the result list route and the record field
 * configuration, the same way the REST API does for /api/v1/index2.
 *
 * Search2.ini applies as usual, including [HiddenFilters]: the model cannot
 * remove them, so they are the right place for licence or visibility rules.
 *
 * @category VuFind
 * @package  Mcp
 * @author   jw-mcp-debug <274549306+jw-mcp-debug@users.noreply.github.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development Wiki
 */
class SearchSearch2 extends AbstractSearch
{
    /**
     * Record field configuration (as for the Search2 REST API).
     *
     * @var string
     */
    protected string $recordFieldConfigFile = 'Search2ApiRecordFields';

    /**
     * Get the search class ID.
     *
     * @return string
     */
    protected function getSearchClassId(): string
    {
        return 'Search2';
    }

    /**
     * Get the route name to perform a search.
     *
     * @return string
     */
    protected function getSearchActionRoute(): string
    {
        return 'search2-results';
    }
}
