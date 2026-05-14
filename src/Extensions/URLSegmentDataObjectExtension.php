<?php

namespace LoveDuckie\SilverStripe\URLSegment\Extensions;

use Exception;
use Portfolio\Models\EventImage;
use Portfolio\Models\ProjectImage;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Extension;
use SilverStripe\View\Parsers\URLSegmentFilter;

class URLSegmentDataObjectExtension extends Extension
{
    use Configurable;

    private static $db = [
        'URLSegment' => 'Varchar(255)'
    ];

    private function getConfig(string $property)
    {
        $config = $this->config();
        if (!$config->get($property)) {
            throw new Exception("Unable to find ${property}");
        }
        $config->get($property);
    }

    private function getImageTypes(): array
    {
        return $this->getConfig('config')['image_types'];
    }

    /**
     * @return void
     */
    public function onBeforeWrite(): void
    {
        if (method_exists(get_parent_class($this), 'onBeforeWrite')) {
            parent::onBeforeWrite();
        }

        if (!$this->owner->hasField('URLSegment')) {
            return;
        }

        $title = $this->owner->Title;
        if (!$title) {
            return;
        }

        $pageTitle = $this->getPageTitle();

        if (!$this->owner->URLSegment) {
            $this->owner->URLSegment = $this->generateUrlSegment($title);
        }

        if (!$this->owner->isInDB() || $this->owner->isChanged('Title', 2)) {
            $this->owner->URLSegment = $this->generateUrlSegment($pageTitle ? "$pageTitle-{$title}" : $title);
            $this->makeUrlSegmentUnique();
        } elseif ($this->owner->isChanged('URLSegment', 2)) {
            $this->owner->URLSegment = $this->generateUrlSegment($this->owner->URLSegment);
            $this->makeUrlSegmentUnique();
        }
    }

    /**
     * @return string|null
     */
    private function getPageTitle(): ?string
    {
        if ($this->owner instanceof ProjectImage) {
            return $this->owner->Project->Title ?? null;
        }

        if ($this->owner instanceof EventImage) {
            return $this->owner->Event->Title ?? null;
        }

        return null;
    }

    /**
     * @return void
     */
    public function onAfterWrite(): void
    {
        if ($this->owner instanceof EventImage || $this->owner instanceof ProjectImage) {
            $this->updateFileName();
        }
    }

    /**
     * @return void
     * @throws NotFoundExceptionInterface
     */
    private function updateFileName(): void
    {
        $currentFileName = $this->owner->getFilename();
        if (!$currentFileName) {
            return;
        }

        $fileParts = pathinfo($currentFileName);
        $urlSegment = strtolower($this->owner->URLSegment);

        if (!str_starts_with($urlSegment, $fileParts['filename'])) {
            $newFileName = "{$fileParts['dirname']}/{$urlSegment}.{$fileParts['extension']}";
            Injector::inst()->get(LoggerInterface::class)->info("Renaming file to $newFileName");
            $this->owner->renameFile($newFileName);
        }
    }

    private function isUrlSegmentInUse(string $urlSegment): bool
    {
        $items = $this->owner::get()->filter('URLSegment', $urlSegment);

        if ($this->owner->isInDB()) {
            $items = $items->exclude('ID', $this->owner->ID);
        }

        return $items->exists();
    }

    /**
     * @return void
     */
    private function makeUrlSegmentUnique(): void
    {
        $count = 2;
        $currentURLSegment = $this->owner->URLSegment;

        while ($this->isUrlSegmentInUse($currentURLSegment)) {
            $currentURLSegment = preg_replace('/-\d+$/', '', $currentURLSegment) . "-$count";
            $count++;
        }

        $this->owner->URLSegment = $currentURLSegment;
    }

    private function createFilteredUrlSegment(string $content): string
    {
        if (!$content) {
            throw new Exception("The content is invalid or null");
        }

        $filter = URLSegmentFilter::create();
        return $filter->filter($content);
    }

    public function generateUrlSegment(?string $title): string
    {
        $className = strtolower($this->owner->ClassName);
        if(is_null($title)) {
            return "{$className}-{$this->owner->ID}";
        }
        $filteredTitle = $this->createFilteredUrlSegment($title);

        return $filteredTitle && $filteredTitle !== '-' ? $filteredTitle : "{$className}-{$this->owner->ID}";
    }
}
