<?php

/**
 * @file classes/search/PreprintSearch.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PreprintSearch
 *
 * @ingroup search
 *
 * @see PreprintSearchDAO
 *
 * @brief Class for retrieving preprint search results.
 *
 */

namespace APP\search;

use APP\core\Application;
use APP\core\Request;
use APP\facades\Repo;
use APP\server\Server;
use PKP\controlledVocab\ControlledVocab;
use PKP\db\DAORegistry;
use PKP\facades\Locale;
use PKP\plugins\Hook;
use PKP\search\SubmissionSearch;
use PKP\submission\PKPSubmission;
use PKP\user\User;
use PKP\userGroup\UserGroup;

class PreprintSearch extends SubmissionSearch
{
    /**
     * See SubmissionSearch::getSparseArray()
     */
    public function getSparseArray($unorderedResults, $orderBy, $orderDir, $exclude)
    {
        // Calculate a well-ordered (unique) score.
        $resultCount = count($unorderedResults);
        $i = 0;
        $contextIds = [];
        foreach ($unorderedResults as $submissionId => $data) {
            $unorderedResults[$submissionId]['score'] = ($resultCount * $data['count']) + $i++;
            $contextIds[] = $data['server_id'];
        }

        // If we got a primary sort order then apply it and use score as secondary
        // order only.
        // NB: We apply order after merging and before paging/formatting. Applying
        // order before merging would require us to retrieve dependent objects for
        // results being purged later. Doing everything in a closed SQL is not
        // possible (e.g. for authors). Applying sort order after paging and
        // formatting is not possible as we have to order the whole list before
        // slicing it. So this seems to be the most appropriate place, although we
        // may have to retrieve some objects again when formatting results.
        $orderedResults = [];

        $contextDao = Application::getContextDAO();
        $contextTitles = [];
        if ($orderBy == 'popularityAll' || $orderBy == 'popularityMonth') {
            // Retrieve a metrics report for all submissions.
            $filter = [
                'submissionIds' => array_keys($unorderedResults),
                'contextIds' => $contextIds,
                'assocTypes' => [Application::ASSOC_TYPE_SUBMISSION, Application::ASSOC_TYPE_SUBMISSION_FILE]
            ];
            if ($orderBy == 'popularityMonth') {
                $oneMonthAgo = date('Ymd', strtotime('-1 month'));
                $today = date('Ymd');
                $filter['dateStart'] = $oneMonthAgo;
                $filter['dateEnd'] = $today;
            }
            $rawReport = app()->get('publicationStats')->getTotals($filter);
            foreach ($rawReport as $row) {
                $unorderedResults[$row->submission_id]['metric'] = $row->metric;
            }
        }

        $i = 0; // Used to prevent ties from clobbering each other
        $authorUserGroups = UserGroup::withRoleIds([\PKP\security\Role::ROLE_ID_AUTHOR])
            ->get();
        foreach ($unorderedResults as $submissionId => $data) {
            // Exclude unwanted IDs.
            if (in_array($submissionId, $exclude)) {
                continue;
            }

            switch ($orderBy) {
                case 'authors':
                    $submission = Repo::submission()->get($submissionId);
                    $orderKey = $submission->getCurrentPublication()->getAuthorString($authorUserGroups);
                    break;

                case 'title':
                    $submission = Repo::submission()->get($submissionId);
                    $orderKey = '';
                    if (!empty($submission->getCurrentPublication())) {
                        $orderKey = $submission->getCurrentPublication()->getLocalizedData('title');
                    }
                    break;

                case 'serverTitle':
                    if (!isset($contextTitles[$data['server_id']])) {
                        /** @var Server */
                        $context = $contextDao->getById($data['server_id']);
                        $contextTitles[$data['server_id']] = $context->getLocalizedName();
                    }
                    $orderKey = $contextTitles[$data['server_id']];
                    break;

                case 'publicationDate':
                    $orderKey = $data[$orderBy];
                    break;

                case 'popularityAll':
                case 'popularityMonth':
                    $orderKey = ($data['metric'] ?? 0);
                    break;

                default: // order by score.
                    $orderKey = $data['score'];
            }
            if (!isset($orderedResults[$orderKey])) {
                $orderedResults[$orderKey] = [];
            }
            $orderedResults[$orderKey][$data['score'] + $i++] = $submissionId;
        }

        // Order the results by primary order.
        if (strtolower($orderDir) == 'asc') {
            ksort($orderedResults);
        } else {
            krsort($orderedResults);
        }

        // Order the result by secondary order and flatten it.
        $finalOrder = [];
        foreach ($orderedResults as $orderKey => $submissionIds) {
            if (count($submissionIds) == 1) {
                $finalOrder[] = array_pop($submissionIds);
            } else {
                if (strtolower($orderDir) == 'asc') {
                    ksort($submissionIds);
                } else {
                    krsort($submissionIds);
                }
                $finalOrder = array_merge($finalOrder, array_values($submissionIds));
            }
        }
        return $finalOrder;
    }

    /**
     * Retrieve the search filters from the request.
     *
     * @param Request $request
     *
     * @return array All search filters (empty and active)
     */
    public function getSearchFilters($request)
    {
        $searchFilters = [
            'query' => $request->getUserVar('query'),
            'searchServer' => $request->getUserVar('searchServer'),
            'abstract' => $request->getUserVar('abstract'),
            'authors' => $request->getUserVar('authors'),
            'title' => $request->getUserVar('title'),
            'galleyFullText' => $request->getUserVar('galleyFullText'),
            'discipline' => $request->getUserVar('discipline'),
            'subject' => $request->getUserVar('subject'),
            'type' => $request->getUserVar('type'),
            'coverage' => $request->getUserVar('coverage'),
            'indexTerms' => $request->getUserVar('indexTerms'),
            'categoryIds' => $request->getUserVar('categoryIds'),
            'sectionIds' => $request->getUserVar('sectionIds'),
        ];

        // Is this a simplified query from the navigation
        // block plugin?
        $simpleQuery = $request->getUserVar('simpleQuery');
        if (!empty($simpleQuery)) {
            // In the case of a simplified query we get the
            // filter type from a drop-down.
            $searchType = $request->getUserVar('searchField');
            if (array_key_exists($searchType, $searchFilters)) {
                $searchFilters[$searchType] = $simpleQuery;
            }
        }

        // Publishing dates.
        $fromDate = $request->getUserDateVar('dateFrom', 1, 1);
        $searchFilters['fromDate'] = (is_null($fromDate) ? null : date('Y-m-d H:i:s', $fromDate));
        $toDate = $request->getUserDateVar('dateTo', 32, 12, null, 23, 59, 59);
        $searchFilters['toDate'] = (is_null($toDate) ? null : date('Y-m-d H:i:s', $toDate));

        // Instantiate the context.
        $context = $request->getContext();
        $siteSearch = !((bool)$context);
        if ($siteSearch) {
            $contextDao = Application::getContextDAO();
            if (!empty($searchFilters['searchServer'])) {
                $context = $contextDao->getById($searchFilters['searchServer']);
            } elseif (array_key_exists('serverTitle', $request->getUserVars())) {
                $contexts = $contextDao->getAll(true);
                while ($context = $contexts->next()) {
                    if (in_array(
                        $request->getUserVar('serverTitle'),
                        (array) $context->getName(null)
                    )) {
                        break;
                    }
                }
            }
        }
        $searchFilters['searchServer'] = $context;
        $searchFilters['siteSearch'] = $siteSearch;

        return $searchFilters;
    }

    /**
     * Load the keywords array from a given search filter.
     *
     * @param array $searchFilters Search filters as returned from
     *  PreprintSearch::getSearchFilters()
     *
     * @return array Keyword array as required by SubmissionSearch::retrieveResults()
     */
    public function getKeywordsFromSearchFilters($searchFilters)
    {
        $indexFieldMap = $this->getIndexFieldMap();
        $indexFieldMap[SubmissionSearch::SUBMISSION_SEARCH_INDEX_TERMS] = 'indexTerms';
        $keywords = [];
        if (isset($searchFilters['query'])) {
            $keywords[''] = $searchFilters['query'];
        }
        foreach ($indexFieldMap as $bitmap => $searchField) {
            if (isset($searchFilters[$searchField]) && !empty($searchFilters[$searchField])) {
                $keywords[$bitmap] = $searchFilters[$searchField];
            }
        }
        return $keywords;
    }

    /**
     * See SubmissionSearch::formatResults()
     *
     * @param User $user optional (if availability information is desired)
     *
     * @return array An array with the preprints, published submissions,
     * server, section.
     */
    public function formatResults(array $results, ?User $user = null): array
    {
        $contextDao = Application::getContextDAO();

        $publishedSubmissionCache = [];
        $preprintCache = [];
        $contextCache = [];
        $sectionCache = [];

        $returner = [];
        foreach ($results as $preprintId) {
            // Get the preprint, storing in cache if necessary.
            if (!isset($preprintCache[$preprintId])) {
                $submission = Repo::submission()->get($preprintId);
                $publishedSubmissionCache[$preprintId] = $submission;
                $preprintCache[$preprintId] = $submission;
            }
            $preprint = $preprintCache[$preprintId];
            $publishedSubmission = $publishedSubmissionCache[$preprintId];

            if ($publishedSubmission && $preprint) {
                $sectionId = $preprint->getSectionId();
                if (!isset($sectionCache[$sectionId])) {
                    $sectionCache[$sectionId] = Repo::section()->get($sectionId);
                }

                // Get the context, storing in cache if necessary.
                $contextId = $preprint->getData('contextId');
                if (!isset($contextCache[$contextId])) {
                    $contextCache[$contextId] = $contextDao->getById($contextId);
                }

                // Store the retrieved objects in the result array.
                $returner[] = [
                    'preprint' => $preprint,
                    'publishedSubmission' => $publishedSubmissionCache[$preprintId],
                    'server' => $contextCache[$contextId],
                    'section' => $sectionCache[$sectionId]
                ];
            }
        }
        return $returner;
    }

    /**
     * Identify similarity terms for a given submission.
     *
     * @param int $submissionId
     *
     * @return null|array An array of string keywords or null
     * if some kind of error occurred.
     *
     * @hook PreprintSearch::getSimilarityTerms [[$submissionId, &$searchTerms]]
     */
    public function getSimilarityTerms($submissionId)
    {
        // Check whether a search plugin provides terms for a similarity search.
        $searchTerms = [];
        $result = Hook::call('PreprintSearch::getSimilarityTerms', [$submissionId, &$searchTerms]);

        // If no plugin implements the hook then use the subject keywords
        // of the submission for a similarity search.
        if ($result === false) {
            // Retrieve the preprint.
            $preprint = Repo::submission()->get($submissionId);
            if ($preprint->getData('status') === PKPSubmission::STATUS_PUBLISHED) {
                // Retrieve keywords (if any).
                $allSearchTerms = array_filter(
                    Repo::controlledVocab()->getBySymbolic(
                        ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_KEYWORD,
                        Application::ASSOC_TYPE_PUBLICATION,
                        $preprint->getId(),
                        [Locale::getLocale(), $preprint->getData('locale'), Locale::getPrimaryLocale()]
                    )
                );
                foreach ($allSearchTerms as $locale => $localeSearchTerms) {
                    $searchTerms += $localeSearchTerms;
                }
            }
        }

        return $searchTerms;
    }

    public function getIndexFieldMap()
    {
        return [
            SubmissionSearch::SUBMISSION_SEARCH_AUTHOR => 'authors',
            SubmissionSearch::SUBMISSION_SEARCH_TITLE => 'title',
            SubmissionSearch::SUBMISSION_SEARCH_ABSTRACT => 'abstract',
            SubmissionSearch::SUBMISSION_SEARCH_GALLEY_FILE => 'galleyFullText',
            SubmissionSearch::SUBMISSION_SEARCH_DISCIPLINE => 'discipline',
            SubmissionSearch::SUBMISSION_SEARCH_SUBJECT => 'subject',
            SubmissionSearch::SUBMISSION_SEARCH_KEYWORD => 'keyword',
            SubmissionSearch::SUBMISSION_SEARCH_TYPE => 'type',
            SubmissionSearch::SUBMISSION_SEARCH_COVERAGE => 'coverage'
        ];
    }

    /**
     * See SubmissionSearch::getResultSetOrderingOptions()
     *
     * @hook SubmissionSearch::getResultSetOrderingOptions [[$context, &$resultSetOrderingOptions]]
     */
    public function getResultSetOrderingOptions($request)
    {
        $resultSetOrderingOptions = [
            'score' => __('search.results.orderBy.relevance'),
            'authors' => __('search.results.orderBy.author'),
            'publicationDate' => __('search.results.orderBy.date'),
            'title' => __('search.results.orderBy.preprint')
        ];

        // Only show the "popularity" options if we have a default metric.
        $resultSetOrderingOptions['popularityAll'] = __('search.results.orderBy.popularityAll');
        $resultSetOrderingOptions['popularityMonth'] = __('search.results.orderBy.popularityMonth');

        // Only show the "server title" option if we have several servers.
        $context = $request->getContext();
        if (!$context) {
            $resultSetOrderingOptions['serverTitle'] = __('search.results.orderBy.server');
        }

        // Let plugins mangle the search ordering options.
        Hook::call(
            'SubmissionSearch::getResultSetOrderingOptions',
            [$context, &$resultSetOrderingOptions]
        );

        return $resultSetOrderingOptions;
    }

    /**
     * See SubmissionSearch::getDefaultOrderDir()
     */
    public function getDefaultOrderDir($orderBy)
    {
        $orderDir = 'asc';
        if (in_array($orderBy, ['score', 'publicationDate', 'popularityAll', 'popularityMonth'])) {
            $orderDir = 'desc';
        }
        return $orderDir;
    }

    /**
     * See SubmissionSearch::getSearchDao()
     */
    protected function getSearchDao()
    {
        return DAORegistry::getDAO('PreprintSearchDAO');
    }
}
