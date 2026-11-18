/* Editor side of the sse-chat/widget block. No build step: plain wp.* globals. */
(function (wp) {
  if (!wp || !wp.blocks || !wp.element) {
    return;
  }
  var el = wp.element.createElement;
  var be = wp.blockEditor || {};
  var c = wp.components || {};
  wp.blocks.registerBlockType('sse-chat/widget', {
    edit: function (props) {
      var a = props.attributes;
      var panel = be.InspectorControls && c.PanelBody && c.TextControl && c.SelectControl
        ? el(be.InspectorControls, null,
          el(c.PanelBody, { title: 'Chat widget' },
            el(c.TextControl, { label: 'Title', value: a.title, onChange: function (v) { props.setAttributes({ title: v }); } }),
            el(c.TextControl, { label: 'Greeting', value: a.greeting, onChange: function (v) { props.setAttributes({ greeting: v }); } }),
            el(c.SelectControl, {
              label: 'Context',
              value: a.context,
              options: [{ label: 'This page', value: 'auto' }, { label: 'None', value: 'none' }],
              onChange: function (v) { props.setAttributes({ context: v }); }
            })))
        : null;
      var props2 = be.useBlockProps ? be.useBlockProps() : {};
      return el('div', props2, panel, el('p', null, 'Chat widget: ' + (a.title || 'default title')));
    },
    save: function () { return null; }
  });
})(window.wp);
