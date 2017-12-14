<?php

namespace Tlapnet\Doxen\Bridge\Parsedown;

use Exception;
use InvalidArgumentException;
use Nette\Application\Responses\CallbackResponse;
use Nette\Http\IRequest;
use Nette\Http\IResponse;
use Nette\Utils\Image;
use Nette\Utils\Strings;
use Tlapnet\Doxen\Component\IListener;
use Tlapnet\Doxen\Event\AbstractEvent;
use Tlapnet\Doxen\Event\NodeEvent;
use Tlapnet\Doxen\Event\SignalEvent;
use Tlapnet\Doxen\Tree\AbstractNode;
use Tlapnet\Doxen\Tree\FileNode;
use Tlapnet\Doxen\Tree\TextNode;

class ParsedownDecorator implements IListener
{

	const SIGNAL_PARSEDOWN_IMAGE = 'parsedown2image';

	/**
	 * @param AbstractEvent $event
	 * @return void
	 */
	public function listen(AbstractEvent $event)
	{
		if ($event->getType() === AbstractEvent::TYPE_NODE) {
			$this->decorateNode($event);
		} elseif ($event->getType() === AbstractEvent::TYPE_SIGNAL) {
			$this->decorateSignal($event);
		}
	}

	/**
	 * @param NodeEvent $event
	 * @return void
	 */
	private function decorateNode(NodeEvent $event)
	{
		/** @var TextNode $node */
		$node = $event->getNode();

		if ($node->getType() !== AbstractNode::TYPE_LEAF) {
			return;
		}

		if (!($node instanceof TextNode)) {
			return;
		}

		$parsedown = new DoxenParsedown($event->getControl());
		$content = $parsedown->text($node->getContent());
		$node->setContent($content);
	}

	/**
	 * @param SignalEvent $event
	 * @return void
	 */
	private function decorateSignal(SignalEvent $event)
	{
		if ($event->getSignal() === self::SIGNAL_PARSEDOWN_IMAGE) {
			$this->processImage($event);
		}
	}

	/**
	 * @param SignalEvent $event
	 * @return void
	 */
	private function processImage(SignalEvent $event)
	{
		$docTree = $event->getDocTree();
		$control = $event->getControl();

		$imageNode = $docTree->getNode($control->page);
		$imageLink = $control->getParameter('imageLink', FALSE);

		// prepare image
		if ($imageNode
			&& ($imageNode instanceof FileNode)
			&& $imageNode->getType() === AbstractNode::TYPE_LEAF
			&& $imageLink
		) {
			$image = $this->getImage($imageNode, $imageLink);
		} else {
			$image = $this->getErrorImage();
		}

		// prepare and send image reponse
		$response = new CallbackResponse(function (IRequest $httpRequest, IResponse $httpResponse) use ($image) {
			$httpResponse->addHeader('Content-Type', 'image/jpeg');
			echo $image->toString(Image::JPEG, 94);
		});

		$control->getPresenter()->sendResponse($response);
	}

	/**
	 * @param FileNode $node
	 * @param string $imageLink
	 * @return Image
	 */
	private function getImage(FileNode $node, $imageLink)
	{
		try {
			// check if image path is part of original doc file content
			if (strpos($node->getContent(), $imageLink) === FALSE) {
				throw new InvalidArgumentException(sprintf("Image path %s is not a part of doc file '%s' content", $imageLink, $node->getFilename()));
			}

			$dirname = pathinfo($node->getFilename(), PATHINFO_DIRNAME);    // /doxen/docs/04_Komponenty/00_ACL
			$imagePath = $dirname . DIRECTORY_SEPARATOR . $imageLink; // /doxen/docs/04_Komponenty/00_ACL/images/database.png

			// check if image file exists
			if (!file_exists($imagePath)) {
				throw new InvalidArgumentException(sprintf('Image file not found %s', $imagePath));
			}

			// check if image path is under documentation file path (image path outside documentation file folder is not allowed for security reasons)
			$imagePath = realpath($imagePath); // /doxen/docs/04_Komponenty/01_ElForm/../00_ACL/images/database.png => /doxen/docs/04_Komponenty/00_ACL/images/database.png
			if (!Strings::startsWith($imagePath, $dirname)) {
				throw new InvalidArgumentException(sprintf('Image path %s is not a part of doc file path %s', $imagePath, $dirname));
			}

			$image = Image::fromFile($imagePath); // UnknownImageFileException if file is not image
		} catch (Exception $e) {
			$image = $this->getErrorImage();
		}

		return $image;
	}


	/**
	 * @return Image
	 */
	private function getErrorImage()
	{
		$image = Image::fromBlank(400, 100, Image::rgb(250, 140, 140));
		$image->string(5, 20, 40, 'Image load problem.', imagecolorallocate($image, 0, 255, 255));

		return $image;
	}

}