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

use MediaWiki\Exception\PermissionsError;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\Utils\UrlUtils;
use OOUI\FieldLayout;
use OOUI\HtmlSnippet;
use OOUI\Tag;
use OOUI\TextInputWidget;

class SpecialUrlShortener extends FormSpecialPage {

	private int $shortenLimit;
	private bool $enabled;

	protected ?FieldLayout $resultField = null;
	protected ?Status $resultStatus = null;

	public function __construct(
		private readonly UrlShortenerUtils $utils,
		private readonly UrlUtils $urlUtils,
		string $name = 'UrlShortener',
		string $restriction = 'urlshortener-create-url'
	) {
		$this->shortenLimit = $this->getConfig()->get( 'UrlShortenerQrCodeShortenLimit' );
		$this->enabled = !$this->getConfig()->get( 'UrlShortenerReadOnly' ) ||
			$this->getConfig()->get( 'UrlShortenerEnableQrCode' );
		parent::__construct( $name, $restriction );
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ) {
		$this->addHelpLink( 'Help:UrlShortener' );

		$this->checkPermissions();

		if ( !$this->enabled ) {
			$this->setHeaders();
			$this->getOutput()->addWikiMsg( 'urlshortener-disabled' );
			return;
		}

		if ( $this->getConfig()->get( 'UrlShortenerEnableQrCode' ) ) {
			$this->getOutput()->addWikiMsg( 'urlshortener-qrcode-enabled' );
		}

		parent::execute( $par );

		if ( $this->resultStatus !== null ) {
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

	protected function getApprovedDomainsMessage(): Message {
		$urlShortenerApprovedDomains = $this->getConfig()->get( 'UrlShortenerApprovedDomains' );
		if ( $urlShortenerApprovedDomains ) {
			$domains = $urlShortenerApprovedDomains;
		} else {
			$parsed = $this->urlUtils->parse( $this->getConfig()->get( 'Server' ) );
			$domains = [ $parsed['host'] ?? '' ];
		}

		$lang = $this->getLanguage();
		return $this->msg( 'urlshortener-approved-domains' )
			->numParams( count( $domains ) )
			->params( $lang->listToText( array_map(
				static fn ( $domain ) => "<code>$domain</code>",
				$domains
			) ) );
	}

	protected function alterForm( HTMLForm $form, string $module = 'ext.urlShortener.special' ) {
		$form
			->setPreHtml( Html::openElement( 'div', [ 'class' => 'ext-urlshortener-container' ] ) )
			->setPostHtml( Html::closeElement( 'div' ) )
			->suppressDefaultSubmit();
		$this->getOutput()->addModules( $module );
		$this->getOutput()->addModuleStyles( [
			'oojs-ui.styles.icons-content',
			'ext.urlShortener.special.styles',
		] );
		$this->getOutput()->addJsConfigVars( [
			'wgUrlShortenerAllowedDomains' => $this->utils->getAllowedDomainsRegex(),
			'wgUrlShortenerAllowArbitraryPorts' => $this->getConfig()->get( 'UrlShortenerAllowArbitraryPorts' ),
			'wgUrlShortenerEnableQrCode' => (bool)$this->getConfig()->get( 'UrlShortenerEnableQrCode' ),
		] );
		if ( $this->resultField !== null ) {
			$form->addFooterHtml( (string)$this->resultField );
		}
		if ( $this->resultStatus !== null && isset( $this->resultStatus->getValue()['qrcode'] ) ) {
			$form->addFooterHtml( $this->getQrCodeHtml() );
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
		$validity_check = $this->utils->validateUrl( $url );
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
				'validation-callback' => $this->validateURL( ... ),
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

		$result = [];

		$status = $this->utils->maybeCreateShortCode( $data['url'], $this->getUser() );
		if ( $status->isOK() ) {
			$result = array_merge( $result, $status->getValue() );
			$this->setShortenedUrlResultField( $result );
		}

		$status = $this->utils->getQrCode(
			$data['url'],
			$this->shortenLimit,
			$this->getUser(),
			true
		);
		if ( $status->isOK() ) {
			$result = array_merge( $result, $status->getValue() );
		}

		$this->resultStatus = Status::newGood( $result );
		return true;
	}

	/**
	 * Create the QR Code HTML based on the current result.
	 */
	private function getQrCodeHtml(): string {
		$qrCodeUri = $this->resultStatus->getValue()['qrcode'];
		$qrCodeImage = Html::element( 'img', [
			'src' => $qrCodeUri
		] );
		$qrCodeNode = Html::rawElement( 'div', [
			'class' => 'ext-urlshortener-qrcode'
		], $qrCodeImage );
		return Html::rawElement(
			'div',
			[ 'class' => 'ext-urlshortener-qrcode-container' ],
			$qrCodeNode . $this->getDownloadButton( $qrCodeUri )
		);
	}

	private function getDownloadButton( string $qrCodeUri ): string {
		// OOUI button
		$buttonElement = new \OOUI\Tag( 'a' );
		$downloadButton = new \OOUI\ButtonWidget( [
			'icon' => 'download',
			'label' => $this->msg( 'urlshortener-toolbox-qrcode' )->text(),
			'button' => $buttonElement,
			'href' => $qrCodeUri
		] );
		$buttonElement->setAttributes( [
			'download' => 'qrcode.svg'
		] );
		$downloadButtonHtml = $downloadButton->toString();
		// OOUI adds a "./" prefix to href for security, but that would break the data URI
		return str_replace( './data:image/', 'data:image/', $downloadButtonHtml );
	}

	protected function setShortenedUrlResultField( array $result ): void {
		if ( !isset( $result['url'] ) ) {
			return;
		}
		$altUrl = $this->utils->makeUrl( $result[ 'alt' ] );
		$altLink = new Tag( 'a' );
		$altLink->setAttributes( [ 'href' => $altUrl ] );
		$altLink->appendContent( $altUrl );
		$this->resultField = new FieldLayout(
			new TextInputWidget( [
				'value' => $this->utils->makeUrl( $result[ 'url' ] ),
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

	/** @inheritDoc */
	public function doesWrites() {
		return true;
	}

	/**
	 * Don't list this page if in read only mode
	 *
	 * @return bool
	 */
	public function isListed() {
		return $this->enabled;
	}
}
