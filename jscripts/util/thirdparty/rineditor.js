QuickReferences.init = function() {
	
	QuickReferences.load_configuration();
	
	// Bind to every CKEditor instance that will load in the future
	CKEDITOR.on('instanceReady', function(event) {
		
		var editor = event.editor;
		
		// Switching from and to source mode
		editor.on('mode', function(e) {
			QuickReferences.load_atwho(this);
		});
		
		// First load
    	QuickReferences.load_atwho(editor);
			
    });
	
}

QuickReferences.load_atwho = function(editor) {
	
	// WYSIWYG mode when switching from source mode
	if (editor.mode != 'source') {

    	editor.document.getBody().$.contentEditable = true;
    	
		$(editor.document.getBody().$)
			.atwho('setIframe', editor.window.getFrame().$)
			.atwho(QuickReferences.configuration);
			
	}
	// Source mode when switching from WYSIWYG
	else {
		$(editor.container.$).find(".cke_source").atwho(QuickReferences.configuration);
	}
	
}