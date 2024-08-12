<?php
/**
 * A special page that creates redirects to arbitrary URLs
 *
 * @file
 * @ingroup Extensions
 * @author Yuvi Panda, http://yuvi.in
 * @copyright © 2014 Yuvaraj Pandian (yuvipanda@gmail.com)
 * @license Apache-2.0
 */

namespace MediaWiki\Extension\UrlShortener;

use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\Status\Status;
use OOUI\FieldLayout;
use OOUI\HtmlSnippet;
use OOUI\Tag;
use OOUI\TextInputWidget;
use PermissionsError;

class SpecialUrlShortener extends FormSpecialPage {

	protected FieldLayout $resultField;
	protected Status $resultStatus;

	/**
	 * @param string $name
	 * @param string $restriction
	 */
	public function __construct( $name = 'UrlShortener', $restriction = 'urlshortener-create-url' ) {
		parent::__construct( $name, $restriction );
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ) {
		$this->addHelpLink( 'Help:UrlShortener' );

		$this->checkPermissions();

		if ( $this->getConfig()->get( 'UrlShortenerReadOnly' ) ) {
			$this->setHeaders();
			$this->getOutput()->addWikiMsg( 'urlshortener-disabled' );
			return;
		}

		parent::execute( $par );

		// @phan-suppress-next-line PhanRedundantCondition
		if ( isset( $this->resultStatus ) ) {
			// Always show form, as in JS mode.
			$form = $this->getForm();
			$form->showAlways();
			$form->addPostHtml( Html::closeElement( 'div' ) );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function displayRestrictionError() {
		throw new PermissionsError( 'urlshortener-create-url', [ 'urlshortener-badaccessgroups' ] );
	}

	/**
	 * @inheritDoc
	 */
	protected function getDisplayFormat() {
		return 'ooui';
	}

	/**
	 * @return Message
	 */
	protected function getApprovedDomainsMessage(): Message {
		$urlShortenerApprovedDomains = $this->getConfig()->get( 'UrlShortenerApprovedDomains' );
		if ( $urlShortenerApprovedDomains ) {
			$domains = $urlShortenerApprovedDomains;
		} else {
			$parsed = wfParseUrl( $this->getConfig()->get( 'Server' ) );
			$domains = [ $parsed['host'] ];
		}

		$lang = $this->getLanguage();
		return $this->msg( 'urlshortener-approved-domains' )
			->numParams( count( $domains ) )
			->params( $lang->listToText( array_map( static function ( $i ) {
				return "<code>$i</code>";
			}, $domains ) ) );
	}

	/**
	 * @param HTMLForm $form
	 * @param string $module
	 */
	protected function alterForm( HTMLForm $form, string $module = 'ext.urlShortener.special' ) {
		$form
			->setPreHtml( Html::openElement( 'div', [ 'class' => 'ext-urlshortener-container' ] ) )
			->setPostHtml( Html::closeElement( 'div' ) )
			->suppressDefaultSubmit();
		$this->getOutput()->addModules( $module );
		$this->getOutput()->addJsConfigVars( [
			'wgUrlShortenerAllowedDomains' => UrlShortenerUtils::getAllowedDomainsRegex(),
			'wgUrlShortenerAllowArbitraryPorts' => $this->getConfig()->get( 'UrlShortenerAllowArbitraryPorts' ),
		] );
		// @phan-suppress-next-line PhanRedundantCondition
		if ( isset( $this->resultField ) ) {
			$form->addFooterHtml( $this->resultField );
		}
	}

	/**
	 * Validate the URL to ensure that we are allowed to create a shorturl for this.
	 *
	 * @param string|null $url The URL to validate
	 * @return bool|string true if url is valid, error message otherwise
	 */
	public function validateURL( ?string $url ) {
		// $url is null when the form hasn't been posted
		if ( $url === null ) {
			// No input
			return true;
		}
		$validity_check = UrlShortenerUtils::validateUrl( $url );
		if ( $validity_check === true ) {
			return true;
		}
		return $validity_check->text();
	}

	/**
	 * Generate the form used to input the URL to shorten.
	 * @return array A form definition that can be used by HTMLForm
	 */
	protected function getFormFields() {
		return [
			'url' => [
				'validation-callback' => [ $this, 'validateURL' ],
				'required' => true,
				'type' => 'textwithbutton',
				'inputtype' => 'url',
				'buttontype' => 'submit',
				'buttondefault' => $this->msg( 'urlshortener-url-input-submit' )->text(),
				'buttonflags' => [ 'primary', 'progressive' ],
				'buttonid' => 'mw-urlshortener-submit',
				'name' => 'url',
				'label-message' => 'urlshortener-form-header',
				'autofocus' => true,
				'id' => 'ext-urlshortener-url-input',
				'help' => $this->getApprovedDomainsMessage()->parse(),
				'placeholder' => $this->msg( 'urlshortener-url-input-label' )->text(),
			],
		];
	}

	/**
	 * @return string
	 */
	protected function getSubpageField() {
		return 'url';
	}

	/**
	 * Process the form on POST submission.
	 *
	 * Creates the short URL and displays it back to the user.
	 *
	 * @param array $data
	 * @return bool|Status True for success, false for didn't-try, Status (with errors) on failure
	 */
	public function onSubmit( array $data ) {
		$out = $this->getOutput();
		$out->enableOOUI();
		if ( $data['url'] === null ) {
			return false;
		}
		$status = UrlShortenerUtils::maybeCreateShortCode( $data['url'], $this->getUser() );
		if ( !$status->isOK() ) {
			return $status;
		}
		$this->setShortenedUrlResultField( $status->getValue() );
		$this->resultStatus = $status;
		return true;
	}

	/**
	 * @param array $result
	 */
	protected function setShortenedUrlResultField( array $result ): void {
		if ( !isset( $result['url'] ) ) {
			// 'url' is only present if it was shortened, which isn't always the case
			// when using Special:QrCode which extends this class.
			return;
		}
		$altUrl = UrlShortenerUtils::makeUrl( $result[ 'alt' ] );
		$altLink = new Tag( 'a' );
		$altLink->setAttributes( [ 'href' => $altUrl ] );
		$altLink->appendContent( $altUrl );
		$this->resultField = new FieldLayout(
			new TextInputWidget( [
				'value' => UrlShortenerUtils::makeUrl( $result[ 'url' ] ),
				'readOnly' => true,
			] ),
			[
				'align' => 'top',
				'classes' => [ 'ext-urlshortener-result' ],
				'label' => $this->msg( 'urlshortener-shortened-url-label' )->text(),
				'help' => new HtmlSnippet(
					$this->msg( 'urlshortener-shortened-url-alt' )->escaped() . ' ' . $altLink
				),
				'helpInline' => true,
			]
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName() {
		return 'pagetools';
	}

	public function doesWrites() {
		return true;
	}

	/**
	 * Don't list this page if in read only mode
	 *
	 * @return bool
	 */
	public function isListed() {
		return !$this->getConfig()->get( 'UrlShortenerReadOnly' );
	}
}
