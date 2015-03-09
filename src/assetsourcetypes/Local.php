<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\assetsourcetypes;

use Craft;
use craft\app\enums\AttributeType;
use craft\app\errors\Exception;
use craft\app\helpers\AssetsHelper;
use craft\app\helpers\IOHelper;
use craft\app\helpers\StringHelper;
use craft\app\elements\Asset;
use craft\app\models\AssetFolder as AssetFolderModel;
use craft\app\models\AssetOperationResponse as AssetOperationResponseModel;
use craft\app\models\AssetTransformIndex as AssetTransformIndexModel;

/**
 * The local asset source type class. Handles the implementation of the local filesystem as an asset source type in
 * Craft.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class Local extends BaseAssetSourceType
{
	// Properties
	// =========================================================================

	/**
	 * @var bool
	 */
	protected $isSourceLocal = true;

	// Public Methods
	// =========================================================================

	/**
	 * @inheritDoc ComponentTypeInterface::getName()
	 *
	 * @return string
	 */
	public function getName()
	{
		return Craft::t('app', 'Local Folder');
	}

	/**
	 * @inheritDoc SavableComponentTypeInterface::getSettingsHtml()
	 *
	 * @return string|null
	 */
	public function getSettingsHtml()
	{
		return Craft::$app->templates->render('_components/assetsourcetypes/Local/settings', [
			'settings' => $this->getSettings()
		]);
	}

	/**
	 * @inheritDoc SavableComponentTypeInterface::prepSettings()
	 *
	 * @param array $settings
	 *
	 * @return array
	 */
	public function prepSettings($settings)
	{
		// Add a trailing slash to the Path and URL settings
		$settings['path'] = !empty($settings['path']) ? rtrim($settings['path'], '/').'/' : '';
		$settings['url'] = !empty($settings['url']) ? rtrim($settings['url'], '/').'/' : '';

		return $settings;
	}

	/**
	 * @inheritDoc BaseAssetSourceType::startIndex()
	 *
	 * @param string $sessionId
	 *
	 * @return array
	 */
	public function startIndex($sessionId)
	{
		$indexedFolderIds = [];

		$indexedFolderIds[Craft::$app->assetIndexing->ensureTopFolder($this->model)] = true;

		$localPath = $this->getSourceFileSystemPath();

		if ($localPath == '/' || !IOHelper::folderExists($localPath) || $localPath === false)
		{
			return ['sourceId' => $this->model->id, 'error' => Craft::t('app', 'The path of your source “{source}” appears to be invalid.', ['source' => $this->model->name])];
		}

		$fileList = IOHelper::getFolderContents($localPath, true);

		if ($fileList && is_array($fileList) && count($fileList) > 0)
		{
			$fileList = array_filter($fileList, function($value) use ($localPath)
			{
				$path = mb_substr($value, StringHelper::length($localPath));
				$segments = explode('/', $path);

				// Ignore the file
				array_pop($segments);

				foreach ($segments as $segment)
				{
					if (isset($segment[0]) && $segment[0] == '_')
					{
						return false;
					}
				}

				return true;
			});
		}

		$offset = 0;
		$total = 0;

		foreach ($fileList as $file)
		{
			if (!preg_match(AssetsHelper::INDEX_SKIP_ITEMS_PATTERN, $file))
			{
				if (is_dir($file))
				{
					$fullPath = rtrim(str_replace($this->getSourceFileSystemPath(), '', $file), '/').'/';
					$folderId = $this->ensureFolderByFullPath($fullPath);
					$indexedFolderIds[$folderId] = true;
				}
				else
				{
					$indexEntry = [
						'sourceId' => $this->model->id,
						'sessionId' => $sessionId,
						'offset' => $offset++,
						'uri' => $file,
						'size' => is_dir($file) ? 0 : filesize($file)
					];

					Craft::$app->assetIndexing->storeIndexEntry($indexEntry);
					$total++;
				}
			}
		}

		$missingFolders = $this->getMissingFolders($indexedFolderIds);

		return ['sourceId' => $this->model->id, 'total' => $total, 'missingFolders' => $missingFolders];
	}

	/**
	 * @inheritDoc BaseAssetSourceType::processIndex()
	 *
	 * @param string $sessionId
	 * @param int    $offset
	 *
	 * @return mixed
	 */
	public function processIndex($sessionId, $offset)
	{
		$indexEntryModel = Craft::$app->assetIndexing->getIndexEntry($this->model->id, $sessionId, $offset);

		if (empty($indexEntryModel))
		{
			return false;
		}

		// Make sure we have a trailing slash. Some people love to skip those.
		$uploadPath = $this->getSourceFileSystemPath();

		$file = $indexEntryModel->uri;

		// This is the part of the path that actually matters
		$uriPath = mb_substr($file, StringHelper::length($uploadPath));

		$fileModel = $this->indexFile($uriPath);

		if ($fileModel)
		{
			Craft::$app->assetIndexing->updateIndexEntryRecordId($indexEntryModel->id, $fileModel->id);

			$fileModel->size = $indexEntryModel->size;
			$fileModel->dateModified = IOHelper::getLastTimeModified($indexEntryModel->uri);

			if ($fileModel->kind == 'image')
			{
				list ($width, $height) = getimagesize($indexEntryModel->uri);
				$fileModel->width = $width;
				$fileModel->height = $height;
			}

			Craft::$app->assets->storeFile($fileModel);

			return $fileModel->id;
		}

		return false;
	}

	/**
	 * @inheritDoc BaseAssetSourceType::putImageTransform()
	 *
	 * @param Asset                    $file
	 * @param AssetTransformIndexModel $index
	 * @param string                   $sourceImage
	 *
	 * @return mixed
	 */
	public function putImageTransform(Asset $file, AssetTransformIndexModel $index, $sourceImage)
	{
		$folder =  $this->getSourceFileSystemPath().$file->getFolder()->path;
		$targetPath = $folder.Craft::$app->assetTransforms->getTransformSubpath($file, $index);
		return IOHelper::copyFile($sourceImage, $targetPath);
	}

	/**
	 * @inheritDoc BaseAssetSourceType::getImageSourcePath()
	 *
	 * @param Asset $file
	 *
	 * @return mixed
	 */
	public function getImageSourcePath(Asset $file)
	{
		return $this->getSourceFileSystemPath().$file->getFolder()->path.$file->filename;
	}

	/**
	 * @inheritDoc BaseAssetSourceType::getLocalCopy()
	 *
	 * @param Asset $file
	 *
	 * @return mixed
	 */

	public function getLocalCopy(Asset $file)
	{
		$location = AssetsHelper::getTempFilePath($file->getExtension());
		IOHelper::copyFile($this->_getFileSystemPath($file), $location);
		clearstatcache();

		return $location;
	}

	/**
	 * @inheritDoc BaseAssetSourceType::folderExists()
	 *
	 * @param AssetFolderModel $parentPath
	 * @param string           $folderName
	 *
	 * @return boolean
	 */
	public function folderExists(AssetFolderModel $parentPath, $folderName)
	{
		return IOHelper::folderExists($this->getSourceFileSystemPath().$parentPath.$folderName);
	}

	/**
	 * @inheritDoc BaseAssetSourceType::getBaseUrl()
	 *
	 * @return string
	 */
	public function getBaseUrl()
	{
		$url = $this->getSettings()->url;

		return Craft::$app->config->parseEnvironmentString($url);
	}

	/**
	 * Returns the source's base server path.
	 *
	 * @return string
	 */
	public function getBasePath()
	{
		$path = $this->getSettings()->path;

		return Craft::$app->config->parseEnvironmentString($path);
	}

	// Protected Methods
	// =========================================================================

	/**
	 * @inheritDoc BaseAssetSourceType::insertFileInFolder()
	 *
	 * @param AssetFolderModel $folder
	 * @param string           $filePath
	 * @param string           $filename
	 *
	 * @throws Exception
	 * @return AssetOperationResponseModel
	 */
	protected function insertFileInFolder(AssetFolderModel $folder, $filePath, $filename)
	{
		// Check if the set file system path exists
		$basePath = $this->getSourceFileSystemPath();

		if (empty($basePath))
		{
			$basePath = $this->getBasePath();

			if (!empty($basePath))
			{
				throw new Exception(Craft::t('app', 'The file system path “{folder}” set for this source does not exist.', ['folder' => $this->getBasePath()]));
			}
		}

		$targetFolder = $this->getSourceFileSystemPath().$folder->path;

		// Make sure the folder exists.
		if (!IOHelper::folderExists($targetFolder))
		{
			throw new Exception(Craft::t('app', 'The folder “{folder}” does not exist.', ['folder' => $targetFolder]));
		}

		// Make sure the folder is writable
		if (!IOHelper::isWritable($targetFolder))
		{
			throw new Exception(Craft::t('app', 'The folder “{folder}” is not writable.', ['folder' => $targetFolder]));
		}

		$filename = AssetsHelper::cleanAssetName($filename);
		$targetPath = $targetFolder.$filename;
		$extension = IOHelper::getExtension($filename);

		if (!IOHelper::isExtensionAllowed($extension))
		{
			throw new Exception(Craft::t('app', 'This file type is not allowed'));
		}

		if (IOHelper::fileExists($targetPath))
		{
			$response = new AssetOperationResponseModel();
			return $response->setPrompt($this->getUserPromptOptions($filename))->setDataItem('filename', $filename);
		}

		if (! IOHelper::copyFile($filePath, $targetPath))
		{
			throw new Exception(Craft::t('app', 'Could not copy file to target destination'));
		}

		IOHelper::changePermissions($targetPath, Craft::$app->config->get('defaultFilePermissions'));

		$response = new AssetOperationResponseModel();

		return $response->setSuccess()->setDataItem('filePath', $targetPath);
	}

	/**
	 * @inheritDoc BaseAssetSourceType::getNameReplacement()
	 *
	 * @param AssetFolderModel $folder
	 * @param string           $filename
	 *
	 * @return string
	 */
	protected function getNameReplacement(AssetFolderModel $folder, $filename)
	{
		$fileList = IOHelper::getFolderContents($this->getSourceFileSystemPath().$folder->path, false);
		$existingFiles = [];

		foreach ($fileList as $file)
		{
			$existingFiles[mb_strtolower(IOHelper::getFilename($file))] = true;
		}

		// Double-check
		if (!isset($existingFiles[mb_strtolower($filename)]))
		{
			return $filename;
		}

		$fileParts = explode(".", $filename);
		$extension = array_pop($fileParts);
		$filename = join(".", $fileParts);

		for ($i = 1; $i <= 50; $i++)
		{
			if (!isset($existingFiles[mb_strtolower($filename.'_'.$i.'.'.$extension)]))
			{
				return $filename.'_'.$i.'.'.$extension;
			}
		}

		return false;
	}

	/**
	 * @inheritDoc BaseSavableComponentType::defineSettings()
	 *
	 * @return array
	 */
	protected function defineSettings()
	{
		return [
			'path' => [AttributeType::String, 'required' => true],
			'url'  => [AttributeType::String, 'required' => true, 'label' => 'URL'],
		];
	}

	/**
	 * Get the file system path for upload source.
	 *
	 * @param Local $sourceType The SourceType.
	 *
	 * @return string
	 */
	protected function getSourceFileSystemPath(Local $sourceType = null)
	{
		$path = is_null($sourceType) ? $this->getBasePath() : $sourceType->getBasePath();
		$path = IOHelper::getRealPath($path);

		return $path;
	}

	/**
	 * @inheritDoc BaseAssetSourceType::deleteSourceFile()
	 *
	 * @param string $subpath
	 *
	 * @return null
	 */
	protected function deleteSourceFile($subpath)
	{
		IOHelper::deleteFile($this->getSourceFileSystemPath().$subpath, true);
	}

	/**
	 * @inheritDoc BaseAssetSourceType::moveSourceFile()
	 *
	 * @param Asset            $file
	 * @param AssetFolderModel $targetFolder
	 * @param string           $filename
	 * @param bool             $overwrite
	 *
	 * @return mixed
	 */
	protected function moveSourceFile(Asset $file, AssetFolderModel $targetFolder, $filename = '', $overwrite = false)
	{
		if (empty($filename))
		{
			$filename = $file->filename;
		}

		$newServerPath = $this->getSourceFileSystemPath().$targetFolder->path.$filename;

		$conflictingRecord = Craft::$app->assets->findFile([
			'folderId' => $targetFolder->id,
			'filename' => $filename
		]);

		$conflict = !$overwrite && (IOHelper::fileExists($newServerPath) || (!Craft::$app->assets->isMergeInProgress() && is_object($conflictingRecord)));

		if ($conflict)
		{
			$response = new AssetOperationResponseModel();
			return $response->setPrompt($this->getUserPromptOptions($filename))->setDataItem('filename', $filename);
		}

		if (!IOHelper::move($this->_getFileSystemPath($file), $newServerPath))
		{
			$response = new AssetOperationResponseModel();
			return $response->setError(Craft::t('app', 'Could not move the file “{filename}”.', ['filename' => $filename]));
		}

		if ($file->kind == 'image')
		{
			if ($targetFolder->sourceId == $file->sourceId)
			{
				$transforms = Craft::$app->assetTransforms->getAllCreatedTransformsForFile($file);

				$destination = clone $file;
				$destination->filename = $filename;

				// Move transforms
				foreach ($transforms as $index)
				{
					// For each file, we have to have both the source and destination
					// for both files and transforms, so we can reliably move them
					$destinationIndex = clone $index;

					if (!empty($index->filename))
					{
						$destinationIndex->filename = $filename;
						Craft::$app->assetTransforms->storeTransformIndexData($destinationIndex);
					}

					$from = $file->getFolder()->path.Craft::$app->assetTransforms->getTransformSubpath($file, $index);
					$to   = $targetFolder->path.Craft::$app->assetTransforms->getTransformSubpath($destination, $destinationIndex);

					$this->copySourceFile($from, $to);
					$this->deleteSourceFile($from);
				}
			}
			else
			{
				Craft::$app->assetTransforms->deleteAllTransformData($file);
			}
		}

		$response = new AssetOperationResponseModel();

		return $response->setSuccess()
				->setDataItem('newId', $file->id)
				->setDataItem('newFilename', $filename);
	}

	/**
	 * @inheritDoc BaseAssetSourceType::copySourceFile()
	 *
	 * @param string $sourceUri
	 * @param string $targetUri
	 *
	 * @return bool
	 */
	protected function copySourceFile($sourceUri, $targetUri)
	{
		return IOHelper::copyFile($this->getSourceFileSystemPath().$sourceUri, $this->getSourceFileSystemPath().$targetUri, true);
	}

	/**
	 * @inheritDoc BaseAssetSourceType::createSourceFolder()
	 *
	 * @param AssetFolderModel $parentFolder
	 * @param string      $folderName
	 *
	 * @return bool
	 */
	protected function createSourceFolder(AssetFolderModel $parentFolder, $folderName)
	{
		if (!IOHelper::isWritable($this->getSourceFileSystemPath().$parentFolder->path))
		{
			return false;
		}

		return IOHelper::createFolder($this->getSourceFileSystemPath().$parentFolder->path.$folderName);
	}

	/**
	 * @inheritDoc BaseAssetSourceType::renameSourceFolder()
	 *
	 * @param AssetFolderModel $folder
	 * @param string           $newName
	 *
	 * @return bool
	 */
	protected function renameSourceFolder(AssetFolderModel $folder, $newName)
	{
		$newFullPath = IOHelper::getParentFolderPath($folder->path).$newName.'/';

		return IOHelper::rename(
			$this->getSourceFileSystemPath().$folder->path,
			$this->getSourceFileSystemPath().$newFullPath);
	}

	/**
	 * @inheritDoc BaseAssetSourceType::deleteSourceFolder()
	 *
	 * @param AssetFolderModel $parentFolder
	 * @param string           $folderName
	 *
	 * @return bool
	 */
	protected function deleteSourceFolder(AssetFolderModel $parentFolder, $folderName)
	{
		return IOHelper::deleteFolder($this->getSourceFileSystemPath().$parentFolder->path.$folderName);
	}

	/**
	 * @inheritDoc BaseAssetSourceType::canMoveFileFrom()
	 *
	 * @param BaseAssetSourceType $originalSource
	 *
	 * @return mixed
	 */
	protected function canMoveFileFrom(BaseAssetSourceType $originalSource)
	{
		return $originalSource->isSourceLocal();
	}

	// Private Methods
	// =========================================================================

	/**
	 * Get a file's system path.
	 *
	 * @param Asset $file
	 *
	 * @return string
	 */
	private function _getFileSystemPath(Asset $file)
	{
		$folder = $file->getFolder();
		$fileSourceType = Craft::$app->assetSources->getSourceTypeById($file->sourceId);

		return $this->getSourceFileSystemPath($fileSourceType).$folder->path.$file->filename;
	}
}
