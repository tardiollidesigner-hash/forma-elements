/**
 * Forma Elements: troca a logo do Elementor pela logo "forma" + etiqueta "Elements".
 *
 * A barra superior do editor e a do painel são React, renderizadas depois do carregamento
 * e redesenhadas a qualquer momento, por isso a troca é refeita sempre que o DOM muda.
 */
( function () {
	'use strict';

	var config = window.formaElementsBrand || {};
	// As logos são achadas pelo desenho, não pelo texto: o título do SVG muda com o idioma.
	// Barra do editor: primeira linha do "E" dentro do círculo.
	var EDITOR_LOGO_PATH = 'M11.7 9H9V22.3';
	// Barra do painel: "E" redondo.
	var ADMIN_LOGO_PATH = 'M12.1207 0C5.42401';

	function createBrand() {
		var brand = document.createElement( 'span' );
		brand.className = 'forma-brand';

		var logo = document.createElement( 'img' );
		logo.className = 'forma-brand__logo';
		logo.src = config.logoUrl;
		logo.alt = 'Forma';

		var tag = document.createElement( 'span' );
		tag.className = 'forma-brand__tag';
		tag.textContent = config.tag || 'Elements';

		brand.appendChild( logo );
		brand.appendChild( tag );
		return brand;
	}

	function brandHost( host, hide ) {
		hide.forEach( function ( node ) {
			node.classList.add( 'forma-brand-hidden' );
		} );
		if ( ! host.querySelector( ':scope > .forma-brand' ) ) {
			host.classList.add( 'forma-brand-host' );
			host.insertBefore( createBrand(), host.firstChild );
		}
	}

	function findLogos( root, pathStart ) {
		var logos = [];
		if ( ! root ) {
			return logos;
		}
		root.querySelectorAll( 'svg path' ).forEach( function ( path ) {
			var svg = path.closest( 'svg' );
			if ( 0 === ( path.getAttribute( 'd' ) || '' ).indexOf( pathStart ) && logos.indexOf( svg ) < 0 ) {
				logos.push( svg );
			}
		} );
		return logos;
	}

	function swapEditorLogo() {
		findLogos( document.getElementById( 'elementor-editor-wrapper-v2' ), EDITOR_LOGO_PATH ).forEach( function ( svg ) {
			brandHost( svg.parentNode, [ svg ] );
		} );
	}

	function swapAdminLogo() {
		findLogos( document.getElementById( 'editor-one-top-bar' ), ADMIN_LOGO_PATH ).forEach( function ( svg ) {
			var host = svg.parentNode;
			// O texto ao lado da logo ("website builder") dá lugar à etiqueta "Elements".
			var hide = Array.prototype.filter.call( host.children, function ( child ) {
				return ! child.classList.contains( 'forma-brand' );
			} );
			brandHost( host, hide );
		} );
	}

	var scheduled = false;
	function run() {
		scheduled = false;
		swapEditorLogo();
		swapAdminLogo();
	}

	function schedule() {
		if ( ! scheduled ) {
			scheduled = true;
			window.requestAnimationFrame( run );
		}
	}

	new MutationObserver( schedule ).observe( document.documentElement, { childList: true, subtree: true } );
	schedule();
}() );
