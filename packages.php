<?php
/**
 * @package      ITP Transifex
 * @subpackage   Plug-ins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2015 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      GNU General Public License version 3 or later; see LICENSE.txt
 */

// no direct access
defined('_JEXEC') or die;

/**
 * ITP Transifex CRON Packages Plug-in.
 *
 * @package      ITP Transifex
 * @subpackage   Plug-ins
 */
class plgItptransifexCronPackages extends JPlugin
{
    public function onCronCreate($context)
    {
        if (strcmp('com_itptransifex.cron.create.packages', $context) !== 0) {
            return;
        }
        jimport('Prism.init');
        jimport('Transifex.init');

        $app    = JFactory::getApplication();
        $params = JComponentHelper::getParams('com_itptransifex');

        // Prepare archives folders.
        $archiveFolder = JPath::clean(JPATH_ROOT . '/' . $params->get('archives_folder', 'tmp/archives'));
        $errorsFolder  = JPath::clean($archiveFolder.'/errors');
        if (!JFolder::exists($archiveFolder)) {
            JFolder::create($archiveFolder);
        }

        if (!JFolder::exists($errorsFolder)) {
            JFolder::create($errorsFolder);
        }

        $projectsIds = (array)$this->params->get('projects_ids');
        $projectsIds = Joomla\Utilities\ArrayHelper::toInteger($projectsIds);

        $package     = $this->getPackage($projectsIds, $params, $archiveFolder, $errorsFolder);

        if ($package !== null and is_array($package) and count($package) > 0) {
            // Get project.
            $project = new Transifex\Project\Project(JFactory::getDbo());
            $project->load($package['project_id']);

            // Check for validation errors.
            if (!$project->getId() or !$project->isPublished()) {
                throw new RuntimeException('Invalid project with ID: '. (int)$package['project_id']);
            }

            $options = array(
                'username'          => $params->get('username'),
                'password'          => $params->get('password'),
                'url'               => $params->get('api_url'),
                'cache_days'        => $params->get('cache_days', 7),
                'tmp_path'          => $app->get('tmp_path'),
                'archives_folder'   => $archiveFolder,
                'include_lang_name' => (bool)$params->get('include_lang_name', 1)
            );

            try {
                $packageBuilder = new Transifex\Package\Builder(JFactory::getDbo(), $project);
                $packageBuilder->setOptions($options);

                $file = $packageBuilder->buildProject($package['language']);

                if (false !== strpos('error.zip', $file)) {
                    $error    = 'Error!';
                    $filePath = JPath::clean($errorsFolder.'/'.$package['filename'].'_'.$package['language'].'.zip');
                    JFile::write($filePath, $error);
                }

            } catch (Exception $e) {
                throw new RuntimeException($e->getMessage());
            }
        }
    }

    /**
     * Get data for project that will be used to generate a language package.
     *
     * @param array $projectsIds
     * @param Joomla\Registry\Registry $params
     * @param string $archiveFolder
     * @param string $errorsFolder
     *
     * @return array
     */
    protected function getPackage($projectsIds, $params, $archiveFolder, $errorsFolder)
    {
        $package   = array();
        $cacheDays = (int)$params->get('cache_days', 7);
        if (!$cacheDays) {
            $cacheDays = 7;
        }

        $db     = JFactory::getDbo();
        $query  = $db->getQuery(true);

        // Get all projects that have packages.
        $query
            ->select('a.project_id, a.language, b.source_language_code, b.filename')
            ->from($db->quoteName('#__itptfx_packages', 'a'))
            ->innerJoin($db->quoteName('#__itptfx_projects', 'b') . ' ON a.project_id = b.id')
            ->group(array('project_id', 'language'));

        if (!$projectsIds) {
            // Prepare sub-query.
            $subQuery  = $db->getQuery(true);
            $subQuery
                ->select('c.id')
                ->from($db->quoteName('#__itptfx_projects', 'c'))
                ->where('c.published = '. (int)Prism\Constants::PUBLISHED)
                ->where('c.filename != ""');

            $query->where('a.project_id IN (' . $subQuery . ')');
        } else {
            $query->where('a.project_id IN (' . implode(',', $projectsIds) . ')');
        }

        $db->setQuery($query);
        $results = (array)$db->loadAssocList();

        // Check for file that has not been generated.
        foreach ($results as $result) {
            // Do not create packages from the source code.
            if (strcmp($result['source_language_code'], $result['language']) === 0) {
                continue;
            }

            $filePath      = JPath::clean($archiveFolder.'/'.$result['filename'].'_'.$result['language'].'.zip');
            $errorFilePath = JPath::clean($errorsFolder.'/'.$result['filename'].'_'.$result['language'].'.zip');

            // Check for files that has occurred errors in the process of package generating.
            // If the file exists, remove it and try to create a package again.
            if (JFile::exists($errorFilePath)) {
                // Check for file with exceeded cache.
                $pastDays = $this->getPastDays($filePath);
                if ($pastDays > $cacheDays) {
                    JFile::delete($errorFilePath);
                    $package = $result;
                    break;
                }

                continue;
            }

            // Check for existing package.
            if (!JFile::exists($filePath)) {
                $package = $result;
                break;
            } else { // The file exists.
                // Check for exceeded cache.
                $pastDays = $this->getPastDays($filePath);
                if ($pastDays > $cacheDays) {
                    // Remove the old file.
                    JFile::delete($filePath);

                    $package = $result;
                    break;
                }
            }
        }

        return $package;
    }

    /**
     * Return number of days from last modification of a file.
     *
     * @param string $filePath
     *
     * @return int
     */
    protected function getPastDays($filePath)
    {
        $time     = filemtime($filePath);

        $fileDate = new DateTime();
        $fileDate->setTimestamp($time);

        $today    = new DateTime();
        $interval = $fileDate->diff($today);

        return (int)$interval->days;
    }
}
