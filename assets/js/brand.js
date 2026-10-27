/**
 * Forma Elements: troca a logo do Elementor pela logo "forma" + pílula "Elements".
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
	// Ícone de menu (hambúrguer) no padrão do UI Kit Forma: traço 1,5 px em grade 24.
	var MENU_ICON = '<svg class="forma-brand__menu" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>';

	function createBrand( withMenu ) {
		var brand = document.createElement( 'span' );
		brand.className = 'forma-brand';
		brand.innerHTML = ( withMenu ? MENU_ICON : '' ) + ( config.logoSvg || '' ) +
			'<span class="forma-brand__tag">' + ( config.tag || 'Elements' ) + '</span>';
		return brand;
	}

	function brandHost( host, hide, withMenu ) {
		hide.forEach( function ( node ) {
			node.classList.add( 'forma-brand-hidden' );
		} );
		if ( ! host.querySelector( ':scope > .forma-brand' ) ) {
			host.classList.add( 'forma-brand-host' );
			host.insertBefore( createBrand( withMenu ), host.firstChild );
		}
	}

	function findLogos( root, pathStart ) {
		var logos = [];
		if ( ! root ) {
			return logos;
		}
		root.querySelectorAll( 'svg path' ).forEach( function ( path ) {
			var svg = path.closest( 'svg' );
			if ( svg.closest( '.forma-brand' ) ) {
				return;
			}
			if ( 0 === ( path.getAttribute( 'd' ) || '' ).indexOf( pathStart ) && logos.indexOf( svg ) < 0 ) {
				logos.push( svg );
			}
		} );
		return logos;
	}

	function swapEditorLogo() {
		findLogos( document.getElementById( 'elementor-editor-wrapper-v2' ), EDITOR_LOGO_PATH ).forEach( function ( svg ) {
			// O botão troca o "E" pelo hambúrguer do Elementor ao passar o mouse: o CSS esconde
			// qualquer SVG dele dentro do botão e mostra o hambúrguer da Forma no lugar.
			brandHost( svg.parentNode, [ svg ], true );
		} );
	}

	function swapAdminLogo() {
		findLogos( document.getElementById( 'editor-one-top-bar' ), ADMIN_LOGO_PATH ).forEach( function ( svg ) {
			var host = svg.parentNode;
			// O texto ao lado da logo ("website builder") dá lugar à pílula "Elements".
			var hide = Array.prototype.filter.call( host.children, function ( child ) {
				return ! child.classList.contains( 'forma-brand' );
			} );
			brandHost( host, hide, false );
		} );
	}

	// Janelas que oferecem a Angie (IA paga do Elementor) ao abrir o editor.
	function hideElementorOffers() {
		document.querySelectorAll( '.MuiDialog-root, .MuiModal-root' ).forEach( function ( dialog ) {
			if ( ! dialog.classList.contains( 'forma-brand-hidden' ) && /\bAngie\b/.test( dialog.textContent ) ) {
				dialog.classList.add( 'forma-brand-hidden' );
			}
		} );
	}

	// A navegação lateral e a barra do construtor no painel são React com classes geradas a cada versão:
	// o que ainda tiver fundo branco é marcado e o CSS (admin.css) aplica a superfície escura do UI Kit.
	function darkenLightSurfaces() {
		[ 'editor-one-sidebar-navigation', 'editor-one-top-bar' ].forEach( function ( id ) {
			var root = document.getElementById( id );
			if ( ! root ) {
				return;
			}
			root.querySelectorAll( '*' ).forEach( function ( element ) {
				if ( 'rgb(255, 255, 255)' === window.getComputedStyle( element ).backgroundColor ) {
					element.classList.add( 'forma-dark-surface' );
				}
			} );
		} );
	}

	var timer = null;
	function run() {
		timer = null;
		swapEditorLogo();
		swapAdminLogo();
		hideElementorOffers();
		darkenLightSurfaces();
	}

	// setTimeout (e não requestAnimationFrame): continua funcionando com a aba em segundo plano.
	function schedule() {
		if ( ! timer ) {
			timer = window.setTimeout( run, 30 );
		}
	}

	new MutationObserver( schedule ).observe( document.documentElement, { childList: true, subtree: true } );
	schedule();
}() );
