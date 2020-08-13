<?php
/**
 * Copyright (C) 2011-2020 Wikimedia Foundation and others.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

namespace MWParsoid\Config;

use Language;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Revision\SlotRoleHandler;
use Parser;
use ParserFactory;
use ParserOptions;
use Title;
use Wikimedia\Parsoid\Config\PageConfig as IPageConfig;
use Wikimedia\Parsoid\Config\PageContent as IPageContent;

/**
 * Page-level configuration interface for Parsoid
 *
 * @todo This belongs in MediaWiki, not Parsoid. We'll move it there when we
 *  get to the point of integrating the two.
 * @todo We should probably deprecate ParserOptions somehow, using a version of
 *  this directly instead.
 */
class PageConfig extends IPageConfig {

	/** @var Parser */
	private $parser;

	/** @var SlotRoleHandler */
	private $slotRoleHandler;

	/** @var Title */
	private $title;

	/** @var ?RevisionRecord */
	private $revision;

	/** @var string|null */
	private $pagelanguage;

	/** @var string|null */
	private $pagelanguageDir;

	/**
	 * @param ParserFactory $parserFactory A factory for either the legacy
	 *   parser or parsoid; it is just used a a container for the revision
	 *   cache stored in the PageConfig.
	 * @param ParserOptions $parserOptions
	 * @param SlotRoleHandler $slotRoleHandler
	 * @param Title $title Title being parsed
	 * @param ?RevisionRecord $revision
	 * @param ?string $pagelanguage
	 * @param ?string $pagelanguageDir
	 */
	public function __construct(
		ParserFactory $parserFactory,
		ParserOptions $parserOptions,
		SlotRoleHandler $slotRoleHandler, Title $title,
		?RevisionRecord $revision = null, ?string $pagelanguage = null,
		?string $pagelanguageDir = null
	) {
		// The Parser object is just a container for the ParserOptions
		// and for a revision cache used by ::getCurrentRevisionRecordOfTitle()
		$this->parser = $parserFactory->create();
		$this->parser->setOptions( $parserOptions );
		$this->slotRoleHandler = $slotRoleHandler;
		$this->title = $title;
		$this->revision = $revision;
		$this->pagelanguage = $pagelanguage;
		$this->pagelanguageDir = $pagelanguageDir;
	}

	/**
	 * Get content model
	 * @return string
	 */
	public function getContentModel(): string {
		// @todo Check just the main slot, or all slots, or what?
		$rev = $this->getRevision();
		if ( $rev ) {
			$content = $rev->getContent( SlotRecord::MAIN );
			if ( $content ) {
				return $content->getModel();
			} else {
				// The page does have a content model but we can't see it. Returning the
				// default model is not really correct. But we can't see the content either
				// so it won't matter much what we do here.
				return $this->slotRoleHandler->getDefaultModel( $this->title );
			}
		} else {
			return $this->slotRoleHandler->getDefaultModel( $this->title );
		}
	}

	public function hasLintableContentModel(): bool {
		// @todo Check just the main slot, or all slots, or what?
		$content = $this->getRevisionContent();
		$model = $content ? $content->getModel( SlotRecord::MAIN ) : null;
		return $content && ( $model === CONTENT_MODEL_WIKITEXT || $model === 'proofread-page' );
	}

	/** @inheritDoc */
	public function getTitle(): string {
		return $this->title->getPrefixedText();
	}

	/** @inheritDoc */
	public function getNs(): int {
		return $this->title->getNamespace();
	}

	/** @inheritDoc */
	public function getPageId(): int {
		return $this->title->getArticleID();
	}

	/** @inheritDoc */
	public function getPageLanguage(): string {
		return $this->pagelanguage ??
			$this->title->getPageLanguage()->getCode();
	}

	/**
	 * Helper function: get the Language object corresponding to
	 * PageConfig::getPageLanguage()
	 * @return Language
	 */
	private function getPageLanguageObject(): Language {
		return $this->pagelanguage ?
			MediaWikiServices::getInstance()->getLanguageFactory()
				->getLanguage( $this->pagelanguage ) :
			$this->title->getPageLanguage();
	}

	/** @inheritDoc */
	public function getPageLanguageDir(): string {
		return $this->pagelanguageDir ??
			$this->getPageLanguageObject()->getDir();
	}

	/**
	 * @return ParserOptions
	 */
	public function getParserOptions(): ParserOptions {
		// We're using $this->parser as a container for the options
		return $this->parser->getOptions();
	}

	/**
	 * Use an LRU cache and the callbacks registered in the
	 * ParserOptions associated with this PageConfig to fetch the
	 * current RevisionRecord for the given title.
	 *
	 * @param Title $title
	 * @return ?RevisionRecord
	 */
	public function getCurrentRevisionRecordOfTitle( Title $title ) {
		// Use the LRU cache from the Parser object; eventually
		// perhaps this cache should be moved into the PageConfig
		// or the ParserOptions, since we don't really need a full
		// Parser object here.  (Note that we're keeping $this->parser
		// private so that no outside caller can see that we're holding
		// a full parser object.)
		return $this->parser->fetchCurrentRevisionRecordOfTitle( $title );
	}

	/**
	 * @return ?RevisionRecord
	 */
	private function getRevision(): ?RevisionRecord {
		if ( $this->revision === null ) {
			$this->revision = $this->getCurrentRevisionRecordOfTitle( $this->title );
			// Note that $this->revision could still be null here.
		}
		return $this->revision;
	}

	/** @inheritDoc */
	public function getRevisionId(): ?int {
		$rev = $this->getRevision();
		return $rev ? $rev->getId() : null;
	}

	/** @inheritDoc */
	public function getParentRevisionId(): ?int {
		$rev = $this->getRevision();
		return $rev ? $rev->getParentId() : null;
	}

	/** @inheritDoc */
	public function getRevisionTimestamp(): ?string {
		$rev = $this->getRevision();
		return $rev ? $rev->getTimestamp() : null;
	}

	/** @inheritDoc */
	public function getRevisionUser(): ?string {
		$rev = $this->getRevision();
		$user = $rev ? $rev->getUser() : null;
		return $user ? $user->getName() : null;
	}

	/** @inheritDoc */
	public function getRevisionUserId(): ?int {
		$rev = $this->getRevision();
		$user = $rev ? $rev->getUser() : null;
		return $user ? $user->getId() : null;
	}

	/** @inheritDoc */
	public function getRevisionSha1(): ?string {
		$rev = $this->getRevision();
		if ( $rev ) {
			// This matches what the Parsoid/JS gets from the API
			// FIXME: Maybe we don't need to do this in the future?
			return \Wikimedia\base_convert( $rev->getSha1(), 36, 16, 40 );
		} else {
			return null;
		}
	}

	/** @inheritDoc */
	public function getRevisionSize(): ?int {
		$rev = $this->getRevision();
		return $rev ? $rev->getSize() : null;
	}

	/** @inheritDoc */
	public function getRevisionContent(): ?IPageContent {
		$rev = $this->getRevision();
		return $rev ? new PageContent( $rev ) : null;
	}

}
