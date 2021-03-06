<?php
/**
 * This file contains only the ProjectRepository class.
 */

namespace Xtools;

use Mediawiki\Api\MediawikiApi;
use Mediawiki\Api\SimpleRequest;
use Psr\Container\ContainerInterface;

/**
 * This class provides data to the Project class.
 */
class ProjectRepository extends Repository
{
    /** @var array Project's 'dbName', 'url' and 'lang'. */
    protected $basicInfo;

    /** @var string[] Basic metadata if XTools is in single-wiki mode. */
    protected $singleBasicInfo;

    /** @var array Full Project metadata, including $basicInfo. */
    protected $metadata;

    /** @var string The cache key for the 'all project' metadata. */
    protected $cacheKeyAllProjects = 'allprojects';

    /**
     * Convenience method to get a new Project object based on a given identification string.
     * @param string $projectIdent The domain name, database name, or URL of a project.
     * @param ContainerInterface $container Symfony's container.
     * @return Project
     */
    public static function getProject($projectIdent, ContainerInterface $container)
    {
        $project = new Project($projectIdent);
        $projectRepo = new ProjectRepository();
        $projectRepo->setContainer($container);
        if ($container->getParameter('app.single_wiki')) {
            $projectRepo->setSingleBasicInfo([
                'url' => $container->getParameter('wiki_url'),
                'dbName' => $container->getParameter('database_replica_name'),
            ]);
        }
        $project->setRepository($projectRepo);
        return $project;
    }

    /**
     * Get the XTools default project.
     * @param ContainerInterface $container
     * @return Project
     */
    public static function getDefaultProject(ContainerInterface $container)
    {
        $defaultProjectName = $container->getParameter('default_project');
        return self::getProject($defaultProjectName, $container);
    }

    /**
     * Get the global 'meta' project, which is either Meta (if this is Labs) or the default project.
     * @return Project
     */
    public function getGlobalProject()
    {
        if ($this->isLabs()) {
            return self::getProject('metawiki', $this->container);
        } else {
            return self::getDefaultProject($this->container);
        }
    }

    /**
     * For single-wiki installations, you must manually set the wiki URL and database name
     * (because there's no meta.wiki database to query).
     * @param $metadata
     * @throws \Exception
     */
    public function setSingleBasicInfo($metadata)
    {
        if (!array_key_exists('url', $metadata) || !array_key_exists('dbName', $metadata)) {
            $error = "Single-wiki metadata should contain 'url', 'dbName' and 'lang' keys.";
            throw new \Exception($error);
        }
        $this->singleBasicInfo = array_intersect_key($metadata, [
            'url' => '',
            'dbName' => '',
            'lang' => '',
        ]);
    }

    /**
     * Get the 'dbName', 'url' and 'lang' of all projects.
     * @return string[] Each item has 'dbName', 'url' and 'lang' keys.
     */
    public function getAll()
    {
        $this->log->debug(__METHOD__." Getting all projects' metadata");
        // Single wiki mode?
        if ($this->singleBasicInfo) {
            return [$this->getOne('')];
        }

        // Maybe we've already fetched it.
        if ($this->cache->hasItem($this->cacheKeyAllProjects)) {
            return $this->cache->getItem($this->cacheKeyAllProjects)->get();
        }

        // Otherwise, fetch all from the database.
        $wikiQuery = $this->getMetaConnection()->createQueryBuilder();
        $wikiQuery->select(['dbname AS dbName', 'url', 'lang'])->from('wiki');
        $projects = $wikiQuery->execute()->fetchAll();
        $projectsMetadata = [];
        foreach ($projects as $project) {
            $projectsMetadata[$project['dbName']] = $project;
        }

        // Cache and return.
        $cacheItem = $this->cache->getItem($this->cacheKeyAllProjects);
        $cacheItem->set($projectsMetadata);
        $cacheItem->expiresAfter(new \DateInterval('P1D'));
        $this->cache->save($cacheItem);
        return $projectsMetadata;
    }

    /**
     * Get the 'dbName', 'url' and 'lang' of a project. This is all you need
     *   to make database queries. More comprehensive metadata can be fetched
     *   with getMetadata() at the expense of an API call.
     * @param string $project A project URL, domain name, or database name.
     * @return string[] With 'dbName', 'url' and 'lang' keys.
     */
    public function getOne($project)
    {
        $this->log->debug(__METHOD__." Getting metadata about $project");
        // For single-wiki setups, every project is the same.
        if ($this->singleBasicInfo) {
            return $this->singleBasicInfo;
        }

        // For muli-wiki setups, first check the cache.
        // First the all-projects cache, then the individual one.
        if ($this->cache->hasItem($this->cacheKeyAllProjects)) {
            foreach ($this->cache->getItem($this->cacheKeyAllProjects)->get() as $projMetadata) {
                if ($projMetadata['dbName'] == "$project"
                    || $projMetadata['url'] == "$project"
                    || $projMetadata['url'] == "https://$project") {
                    $this->log->debug(__METHOD__ . " Using cached data for $project");
                    return $projMetadata;
                }
            }
        }
        $cacheKey = "project.$project";
        if ($this->cache->hasItem($cacheKey)) {
            return $this->cache->getItem($cacheKey)->get();
        }

        // Otherwise, fetch the project's metadata from the meta.wiki table.
        $wikiQuery = $this->getMetaConnection()->createQueryBuilder();
        $wikiQuery->select(['dbname AS dbName', 'url', 'lang'])
            ->from('wiki')
            ->where($wikiQuery->expr()->eq('dbname', ':project'))
            // The meta database will have the project's URL stored as https://en.wikipedia.org
            // so we need to query for it accordingly, trying different variations the user
            // might have inputted.
            ->orwhere($wikiQuery->expr()->like('url', ':projectUrl'))
            ->orwhere($wikiQuery->expr()
                ->like('url', ':projectUrl2'))
            ->setParameter('project', $project)
            ->setParameter('projectUrl', "https://$project")
            ->setParameter('projectUrl2', "https://$project.org");
        $wikiStatement = $wikiQuery->execute();

        // Fetch and cache the wiki data.
        $basicInfo = $wikiStatement->fetch();

        $cacheItem = $this->cache->getItem($cacheKey);
        $cacheItem->set($basicInfo)
            ->expiresAfter(new \DateInterval('PT1H'));
        $this->cache->save($cacheItem);

        return $basicInfo;
    }

    /**
     * Get metadata about a project, including the 'dbName', 'url' and 'lang'
     *
     * @param string $projectUrl The project's URL.
     * @return array With 'dbName', 'url', 'lang', 'general' and 'namespaces' keys.
     *   'general' contains: 'wikiName', 'articlePath', 'scriptPath', 'script',
     *   'timezone', and 'timezoneOffset'; 'namespaces' contains all namespace
     *   names, keyed by their IDs.
     */
    public function getMetadata($projectUrl)
    {
        // First try variable cache
        if ($this->metadata) {
            return $this->metadata;
        }

        // Redis cache
        $cacheKey = "projectMetadata." . preg_replace("/[^A-Za-z0-9]/", '', $projectUrl);
        if ($this->cache->hasItem($cacheKey)) {
            $this->metadata = $this->cache->getItem($cacheKey)->get();
            return $this->metadata;
        }

        $api = MediawikiApi::newFromPage($projectUrl);

        $params = ['meta' => 'siteinfo', 'siprop' => 'general|namespaces'];
        $query = new SimpleRequest('query', $params);

        $this->metadata = [
            'general' => [],
            'namespaces' => [],
        ];

        // Even if general info could not be fetched,
        //   return dbName, url and lang if already known
        if ($this->basicInfo) {
            $this->metadata['dbName'] = $this->basicInfo['dbName'];
            $this->metadata['url'] = $this->basicInfo['url'];
            $this->metadata['lang'] = $this->basicInfo['lang'];
        }

        $res = $api->getRequest($query);

        if (isset($res['query']['general'])) {
            $info = $res['query']['general'];

            $this->metadata['dbName'] = $info['wikiid'];
            $this->metadata['url'] = $info['server'];
            $this->metadata['lang'] = $info['lang'];

            $this->metadata['general'] = [
                'wikiName' => $info['sitename'],
                'articlePath' => $info['articlepath'],
                'scriptPath' => $info['scriptpath'],
                'script' => $info['script'],
                'timezone' => $info['timezone'],
                'timeOffset' => $info['timeoffset'],
            ];
        }

        if (isset($res['query']['namespaces'])) {
            foreach ($res['query']['namespaces'] as $namespace) {
                if ($namespace['id'] < 0) {
                    continue;
                }

                if (isset($namespace['name'])) {
                    $name = $namespace['name'];
                } elseif (isset($namespace['*'])) {
                    $name = $namespace['*'];
                } else {
                    continue;
                }

                $this->metadata['namespaces'][$namespace['id']] = $name;
            }
        }

        $cacheItem = $this->cache->getItem($cacheKey);
        $cacheItem->set($this->metadata)
            ->expiresAfter(new \DateInterval('PT1H'));
        $this->cache->save($cacheItem);

        return $this->metadata;
    }

    /**
     * Get a list of projects that have opted in to having all their users' restricted statistics
     * available to anyone.
     *
     * @return string[]
     */
    public function optedIn()
    {
        $optedIn = $this->container->getParameter('opted_in');
        // In case there's just one given.
        if (!is_array($optedIn)) {
            $optedIn = [ $optedIn ];
        }
        return $optedIn;
    }

    /**
     * The path to api.php
     *
     * @return string
     */
    public function getApiPath()
    {
        return $this->container->getParameter('api_path');
    }

    /**
     * Get a page from the given Project.
     * @param Project $project The project.
     * @param string $pageName The name of the page.
     * @return Page
     */
    public function getPage(Project $project, $pageName)
    {
        $pageRepo = new PagesRepository();
        $pageRepo->setContainer($this->container);
        $page = new Page($project, $pageName);
        $page->setRepository($pageRepo);
        return $page;
    }

    /**
     * Check to see if a page exists on this project and has some content.
     * @param Project $project The project.
     * @param int $namespaceId The page namespace ID.
     * @param string $pageTitle The page title, without namespace.
     * @return bool
     */
    public function pageHasContent(Project $project, $namespaceId, $pageTitle)
    {
        $conn = $this->getProjectsConnection();
        $pageTable = $this->getTableName($project->getDatabaseName(), 'page');
        $query = "SELECT page_id "
             . " FROM $pageTable "
             . " WHERE page_namespace = :ns AND page_title = :title AND page_len > 0 "
             . " LIMIT 1";
        $params = [
            'ns' => $namespaceId,
            'title' => $pageTitle,
        ];
        $pages = $conn->executeQuery($query, $params)->fetchAll();
        return count($pages) > 0;
    }

    /**
     * Get page assessments configuration for this project
     *   and cache in static variable.
     * @param string $projectDomain The project domain such as en.wikipedia.org
     * @return string[]|bool As defined in config/assessments.yml,
     *                       or false if none exists
     */
    public function getAssessmentsConfig($projectDomain)
    {
        static $assessmentsConfig = null;
        if ($assessmentsConfig !== null) {
            return $assessmentsConfig;
        }

        $projectsConfig = $this->container->getParameter('assessments');

        if (isset($projectsConfig[$projectDomain])) {
            $assessmentsConfig = $projectsConfig[$projectDomain];
        } else {
            $assessmentsConfig = false;
        }

        return $assessmentsConfig;
    }
}
