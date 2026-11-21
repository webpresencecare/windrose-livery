/**
 * Style Mermaid on a public page (the block windrose/style-mermaid): download only.
 */
import { createRoot } from '@wordpress/element';
import MermaidApp from './app';
import './mermaid.scss';

const config = window.windroseLiveryMermaid || {};
document
	.querySelectorAll( '[data-windrose-livery-mermaid]' )
	.forEach( ( element ) =>
		createRoot( element ).render( <MermaidApp config={ config } /> )
	);
