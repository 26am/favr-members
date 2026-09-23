/**
 * Favr Members — block editor: the Members Only block and a "Members only" page toggle.
 * No build step; uses WordPress globals.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var be = wp.blockEditor;
	var C = wp.components;

	wp.blocks.registerBlockType( 'favr-members/members-only', {
		edit: function ( props ) {
			var blockProps = be.useBlockProps( { className: 'favr-members-only-editor' } );
			return el(
				wp.element.Fragment,
				null,
				el(
					be.InspectorControls,
					null,
					el(
						C.PanelBody,
						{ title: __( 'Visitors who aren’t members', 'favr-members' ) },
						el( C.TextControl, {
							label: __( 'Message', 'favr-members' ),
							help: __( 'Shown instead of this content, with a login button.', 'favr-members' ),
							placeholder: __( 'This content is for members.', 'favr-members' ),
							value: props.attributes.message,
							onChange: function ( v ) {
								props.setAttributes( { message: v } );
							}
						} )
					)
				),
				el(
					'div',
					blockProps,
					el( 'div', { className: 'favr-members-only-editor__label' }, '🔒 ' + __( 'Members only', 'favr-members' ) ),
					el( be.InnerBlocks, { templateLock: false } )
				)
			);
		},
		save: function () {
			return el( be.InnerBlocks.Content );
		}
	} );

	// Login, account and application: server-rendered on the site; a clear placeholder here.
	[
		[ 'favr-members/login', 'unlock', __( 'Member Login', 'favr-members' ), __( 'Visitors log in here (with forgot-password and set-new-password steps).', 'favr-members' ) ],
		[ 'favr-members/account', 'id-alt', __( 'Member Account', 'favr-members' ), __( 'Members see their dashboard here: overview, profile, My Listing and My Events. Visitors see the login form.', 'favr-members' ) ],
		[ 'favr-members/register', 'welcome-add-page', __( 'Membership Application', 'favr-members' ), __( 'The application form, when applications are open in Members → Settings.', 'favr-members' ) ]
	].forEach( function ( def ) {
		wp.blocks.registerBlockType( def[ 0 ], {
			edit: function () {
				return el( 'div', be.useBlockProps(), el( C.Placeholder, { icon: def[ 1 ], label: def[ 2 ], instructions: def[ 3 ] } ) );
			},
			save: function () {
				return null;
			}
		} );

	// "Members only" toggle in the page/post sidebar.
	var PluginPanel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	if ( ! PluginPanel || ! wp.plugins ) {
		return;
	}

	function GatePanel() {
		var postType = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostType();
		}, [] );
		var entity = wp.coreData.useEntityProp( 'postType', postType, 'meta' );
		var meta = entity[ 0 ] || {};
		var setMeta = entity[ 1 ];
		if ( 'page' !== postType && 'post' !== postType ) {
			return null;
		}
		return el(
			PluginPanel,
			{ name: 'favr-members-gate', title: __( 'Member access', 'favr-members' ), icon: 'lock' },
			el( C.ToggleControl, {
				label: __( 'Members only', 'favr-members' ),
				help: __( 'Only current members can see this page’s content. It’s also hidden from search engines.', 'favr-members' ),
				checked: !! meta._favr_members_only,
				onChange: function ( v ) {
					setMeta( Object.assign( {}, meta, { _favr_members_only: v } ) );
				}
			} )
		);
	}

	wp.plugins.registerPlugin( 'favr-members-gate', { render: GatePanel } );

	} );
} )( window.wp );
