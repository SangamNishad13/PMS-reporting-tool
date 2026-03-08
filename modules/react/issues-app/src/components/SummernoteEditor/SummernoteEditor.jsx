import { useEffect, useRef } from 'react';
import './SummernoteEditor.css';

const SummernoteEditor = ({ 
  value = '', 
  onChange, 
  placeholder = '', 
  minHeight = 200,
  projectId,
  enableMentions = false // New prop to enable mention functionality
}) => {
  const editorRef = useRef(null);
  const isInitialized = useRef(false);
  const onChangeRef = useRef(onChange); // Store onChange callback
  const isTyping = useRef(false); // Track if user is actively typing
  const typingTimeout = useRef(null);
  const lastContent = useRef(''); // Track last content to prevent unnecessary updates
  
  // Update onChange ref when it changes
  useEffect(() => {
    onChangeRef.current = onChange;
  }, [onChange]);
  
  // Image upload state (matching PHP issueData.imageUpload)
  const imageUploadState = useRef({
    lastPasteTime: 0,
    savedRange: null,
    pendingFile: null,
    pendingEditor: null,
    isEditing: false,
    editingImg: null
  });

  useEffect(() => {
    if (!editorRef.current || isInitialized.current) return;
    if (!window.jQuery || !window.jQuery.fn.summernote) {
      return;
    }

    const $editor = window.jQuery(editorRef.current);
    
    // Create alt text modal (matching PHP showImageAltModal)
    const createAltTextModal = () => {
      let $modal = window.jQuery('#imageAltTextModal');
      if (!$modal.length) {
        const modalHtml = `
          <div class="modal fade" id="imageAltTextModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
            <div class="modal-dialog modal-dialog-centered modal-sm">
              <div class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title">Image Alt-Text</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                  <label class="form-label">Enter descriptive alt-text for this image:</label>
                  <input type="text" class="form-control" id="imageAltTextInput" placeholder="e.g., Screenshot showing login error" value="Issue Screenshot">
                  <div class="form-text">Alt-text helps with accessibility and SEO.</div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="btnCancelAltText">Cancel</button>
                  <button type="button" class="btn btn-primary" id="btnConfirmAltText">Upload Image</button>
                </div>
              </div>
            </div>
          </div>
        `;
        window.jQuery('body').append(modalHtml);
        $modal = window.jQuery('#imageAltTextModal');
        
        // Handle confirm button
        window.jQuery('#btnConfirmAltText').on('click', confirmImageAltText);
        
        // Handle cancel button - upload with default alt text
        window.jQuery('#btnCancelAltText').on('click', function() {
          // Set default alt text and confirm
          window.jQuery('#imageAltTextInput').val('Issue Screenshot');
          confirmImageAltText();
        });
        
        // Handle Enter key
        window.jQuery('#imageAltTextInput').on('keypress', function(e) {
          if (e.which === 13) {
            e.preventDefault();
            confirmImageAltText();
          }
        });
      }
      return $modal;
    };
    
    // Show alt text modal
    const showImageAltModal = (currentAlt = 'Issue Screenshot') => {
      const $modal = createAltTextModal();
      window.jQuery('#imageAltTextInput').val(currentAlt);
      const modal = new window.bootstrap.Modal($modal[0]);
      modal.show();
      $modal.one('shown.bs.modal', function() {
        window.jQuery('#imageAltTextInput').focus().select();
      });
    };
    
    // Confirm and upload image (matching PHP confirmImageAltText)
    const confirmImageAltText = () => {
      const altText = window.jQuery('#imageAltTextInput').val().trim();
      
      // Handle editing existing image alt text
      if (imageUploadState.current.isEditing && imageUploadState.current.editingImg) {
        imageUploadState.current.editingImg.attr('alt', altText || 'Issue Screenshot');
        window.bootstrap.Modal.getInstance(window.jQuery('#imageAltTextModal')[0]).hide();
        imageUploadState.current.isEditing = false;
        imageUploadState.current.editingImg = null;
        return;
      }
      
      // Handle new image upload
      if (imageUploadState.current.pendingFile && imageUploadState.current.pendingEditor) {
        const file = imageUploadState.current.pendingFile;
        const $el = imageUploadState.current.pendingEditor;
        
        // Use PMSSummernoteImage helper if available, otherwise fallback to fetch
        const baseDir = window.ProjectConfig?.baseDir || '';
        const uploadUrl = baseDir + '/api/issue_upload_image.php';
        
        const uploadPromise = (window.PMSSummernoteImage && typeof window.PMSSummernoteImage.uploadImage === 'function')
          ? window.PMSSummernoteImage.uploadImage(file, { 
              uploadUrl: uploadUrl, 
              credentials: 'same-origin' 
            })
          : (function() {
              const fd = new FormData();
              fd.append('image', file);
              return fetch(uploadUrl, { 
                method: 'POST', 
                body: fd, 
                credentials: 'same-origin' 
              }).then(r => r.json());
            })();
        
        uploadPromise
          .then((res) => {
            if (res && res.success && res.url) {
              const safeAlt = (altText || 'Issue Screenshot').replace(/"/g, '&quot;');
              const imgHtml = `<img src="${res.url}" alt="${safeAlt}" style="max-width:100%; height:auto; cursor:pointer;" class="editable-issue-image" />`;
              
              // Hide modal first
              const modalInstance = window.bootstrap.Modal.getInstance(window.jQuery('#imageAltTextModal')[0]);
              if (modalInstance) {
                modalInstance.hide();
              }
              
              // Wait for modal to close, then insert image
              setTimeout(() => {
                // Restore saved range and insert image
                if (imageUploadState.current.savedRange) {
                  try {
                    $el.summernote('focus');
                    $el.summernote('restoreRange');
                    imageUploadState.current.savedRange.pasteHTML(imgHtml);
                    imageUploadState.current.savedRange = null;
                  } catch (e) {
                    $el.summernote('pasteHTML', imgHtml);
                  }
                } else {
                  $el.summernote('pasteHTML', imgHtml);
                }
                
                // Manually trigger onChange to update React state
                const newContent = $el.summernote('code');
                
                // Call React onChange callback directly
                if (onChangeRef.current) {
                  onChangeRef.current(newContent);
                }
              }, 300);
            } else if (res && res.error) {
              alert(res.error);
            }
          })
          .catch((err) => {
            alert('Image upload failed');
          })
          .finally(() => {
            imageUploadState.current.pendingFile = null;
            imageUploadState.current.pendingEditor = null;
          });
      }
    };
    
    // Upload image function (matching PHP uploadIssueImage)
    const uploadIssueImage = (file, $el) => {
      if (!file || !file.type || !file.type.startsWith('image/')) {
        return;
      }
      
      const now = Date.now();
      // Prevent duplicate uploads within 500ms
      if (now - imageUploadState.current.lastPasteTime < 500) {
        return;
      }
      imageUploadState.current.lastPasteTime = now;
      
      // Save current cursor position
      imageUploadState.current.savedRange = $el.summernote('createRange');
      imageUploadState.current.pendingFile = file;
      imageUploadState.current.pendingEditor = $el;
      imageUploadState.current.isEditing = false;
      
      // Show alt text modal
      showImageAltModal('Issue Screenshot');
    };
    
    // Initialize Summernote (matching PHP implementation)
    const summernoteConfig = {
      height: minHeight,
      placeholder: placeholder,
      toolbar: [
        ['style', ['style']],
        ['font', ['bold', 'italic', 'underline', 'strikethrough', 'clear']],
        ['fontname', ['fontname']],
        ['fontsize', ['fontsize']],
        ['color', ['color']],
        ['para', ['ul', 'ol', 'paragraph']],
        ['table', ['table']],
        ['insert', ['link', 'picture', 'video', 'hr']],
        ['view', ['fullscreen', 'codeview', 'help']]
      ],
      popover: {
        image: [
          ['image', ['resizeFull', 'resizeHalf', 'resizeQuarter', 'resizeNone']],
          ['float', ['floatLeft', 'floatRight', 'floatNone']],
          ['remove', ['removeMedia']],
          ['custom', ['imageAltText']]
        ]
      },
      buttons: {
        imageAltText: function(context) {
          const ui = window.jQuery.summernote.ui;
          return ui.button({
            contents: '<i class="fas fa-tag"/> <span style="font-size:0.75em;">Alt Text</span>',
            tooltip: 'Edit alt text',
            click: function() {
              const $img = window.jQuery(context.invoke('restoreTarget'));
              if ($img && $img.length) {
                imageUploadState.current.isEditing = true;
                imageUploadState.current.editingImg = $img;
                showImageAltModal($img.attr('alt') || 'Issue Screenshot');
              }
            }
          }).render();
        }
      },
      callbacks: {
        onInit: function() {
          // Enable keyboard navigation for toolbar after init
          setTimeout(() => {
            enableToolbarKeyboardNavigation($editor);
          }, 100);
        },
        onChange: (contents) => {
          // Mark that user is typing
          isTyping.current = true;
          
          // Clear previous timeout
          if (typingTimeout.current) {
            clearTimeout(typingTimeout.current);
          }
          
          // Only call onChange if content actually changed
          if (contents !== lastContent.current) {
            lastContent.current = contents;
            
            // Debounce onChange to prevent rapid re-renders
            typingTimeout.current = setTimeout(() => {
              if (onChangeRef.current) {
                onChangeRef.current(contents);
              }
              isTyping.current = false;
            }, 150); // 150ms debounce
          }
        },
        onKeydown: function(e) {
          // Mark as typing on any keypress
          isTyping.current = true;
          
          // Handle Alt+F10 to focus toolbar (like project chat)
          if (e && e.altKey && (e.key === 'F10' || e.keyCode === 121)) {
            e.preventDefault();
            focusEditorToolbar($editor);
          }
          
          // Handle Alt+F to toggle fullscreen
          if (e && e.altKey && e.key === 'f') {
            e.preventDefault();
            // Try multiple selectors to find fullscreen button
            const $noteEditor = $editor.next('.note-editor');
            let fullscreenBtn = $noteEditor.find('button[data-event="fullscreen"]');
            if (!fullscreenBtn.length) {
              fullscreenBtn = $noteEditor.find('.note-btn-fullscreen');
            }
            if (!fullscreenBtn.length) {
              fullscreenBtn = $noteEditor.find('.note-toolbar button').filter(function() {
                return window.jQuery(this).attr('data-original-title') === 'Full Screen' || 
                       window.jQuery(this).attr('title') === 'Full Screen' ||
                       window.jQuery(this).find('.note-icon-arrows-alt').length > 0;
              });
            }
            if (fullscreenBtn.length) {
              fullscreenBtn.first().trigger('click');
            }
          }
        },
        onKeyUp: function(e) {
          // Keep typing flag for a bit after keyup
          setTimeout(() => {
            isTyping.current = false;
          }, 200);
        },
        onImageUpload: function(files) {
          // This handles both: picture button click AND image paste
          const list = files || [];
          for (let i = 0; i < list.length; i++) {
            uploadIssueImage(list[i], $editor);
          }
        }
      }
    };
    
    // Add mention functionality if enabled
    if (enableMentions && window.ProjectConfig?.projectUsers) {
      const users = window.ProjectConfig.projectUsers || [];
      
      summernoteConfig.hint = [{
        mentions: users.map(user => ({
          id: user.id,
          name: user.full_name,
          email: user.email || ''
        })),
        match: /\B@(\w*)$/,
        search: function (keyword, callback) {
          callback(window.jQuery.grep(this.mentions, function (item) {
            return item.name.toLowerCase().indexOf(keyword.toLowerCase()) === 0;
          }));
        },
        template: function (item) {
          return '<div class="mention-item">' +
                 '<strong>' + item.name + '</strong>' +
                 (item.email ? '<div class="small text-muted">' + item.email + '</div>' : '') +
                 '</div>';
        },
        content: function (item) {
          // Return just the name with @ - Summernote will insert it as text
          // We'll style it with CSS instead of using contenteditable=false span
          return '@' + item.name + ' ';
        }
      }];
    }
    
    $editor.summernote(summernoteConfig);

    // Set initial value
    if (value) {
      $editor.summernote('code', value);
    }

    // Function to focus toolbar (like project chat)
    const focusEditorToolbar = ($ed) => {
      if (!$ed || !$ed.length) return;
      const $toolbar = $ed.next('.note-editor').find('.note-toolbar').first();
      if (!$toolbar.length) return;
      const $items = $toolbar.find('.note-btn-group button').filter(function() {
        const $b = window.jQuery(this);
        return !$b.is(':hidden') && !$b.prop('disabled') && !$b.closest('.dropdown-menu').length;
      });
      if (!$items.length) return;
      $items.attr('tabindex', '-1');
      $items.eq(0).attr('tabindex', '0').focus();
      $toolbar.data('kbdIndex', 0);
    };

    // Function to enable keyboard navigation (like project chat)
    const enableToolbarKeyboardNavigation = ($ed) => {
      if (!$ed || !$ed.length) return;
      const $toolbar = $ed.next('.note-editor').find('.note-toolbar').first();
      if (!$toolbar.length || $toolbar.data('kbdA11yBound')) return;

      const getItems = () => {
        return $toolbar.find('.note-btn-group button').filter(function() {
          const $b = window.jQuery(this);
          return !$b.is(':hidden') && !$b.prop('disabled') && !$b.closest('.dropdown-menu').length;
        });
      };

      const setActiveIndex = (idx) => {
        const $items = getItems();
        if (!$items.length) return;
        const next = Math.max(0, Math.min(idx, $items.length - 1));
        $items.each(function(i) {
          const val = i === next ? '0' : '-1';
          if (this.getAttribute('tabindex') !== val) {
            this.setAttribute('tabindex', val);
          }
        });
        $toolbar.data('kbdIndex', next);
      };

      const ensureToolbarTabStops = () => {
        const $items = getItems();
        if (!$items.length) return;
        let idx = parseInt($toolbar.data('kbdIndex'), 10);
        if (isNaN(idx) || idx < 0 || idx >= $items.length) {
          idx = $items.index(document.activeElement);
        }
        if (isNaN(idx) || idx < 0 || idx >= $items.length) idx = 0;
        setActiveIndex(idx);
      };

      const handleNav = (e) => {
        const key = e.key || (e.originalEvent && e.originalEvent.key);
        const code = e.keyCode || (e.originalEvent && e.originalEvent.keyCode);
        const isRight = key === 'ArrowRight' || code === 39;
        const isLeft = key === 'ArrowLeft' || code === 37;
        const isHome = key === 'Home' || code === 36;
        const isEnd = key === 'End' || code === 35;
        if (!isRight && !isLeft && !isHome && !isEnd) return;
        
        const $items = getItems();
        if (!$items.length) return;
        const activeEl = document.activeElement;
        let idx = $items.index(activeEl);
        if (idx < 0 && activeEl && activeEl.closest) {
          const parentBtn = activeEl.closest('button');
          if (parentBtn) idx = $items.index(parentBtn);
        }
        if (idx < 0) {
          const saved = parseInt($toolbar.data('kbdIndex'), 10);
          if (!isNaN(saved) && saved >= 0 && saved < $items.length) idx = saved;
        }
        if (isNaN(idx) || idx < 0) idx = 0;
        
        e.preventDefault();
        if (e.stopPropagation) e.stopPropagation();
        
        if (isHome) idx = 0;
        else if (isEnd) idx = $items.length - 1;
        else if (isRight) idx = (idx + 1) % $items.length;
        else if (isLeft) idx = (idx - 1 + $items.length) % $items.length;
        
        setActiveIndex(idx);
        $items.eq(idx).focus();
      };

      $toolbar.attr('role', 'toolbar');
      if (!$toolbar.attr('aria-label')) $toolbar.attr('aria-label', 'Editor toolbar');
      ensureToolbarTabStops();
      
      $toolbar.on('focusin', 'button, [role="button"], a.note-btn', function() {
        const $items = getItems();
        const idx = $items.index(this);
        if (idx >= 0) setActiveIndex(idx);
      });
      
      $toolbar.on('click', 'button, [role="button"], a.note-btn', function() {
        const $items = getItems();
        const idx = $items.index(this);
        if (idx >= 0) setActiveIndex(idx);
      });
      
      $toolbar.on('keydown', handleNav);
      $toolbar.get(0).addEventListener('keydown', handleNav, true);
      $toolbar.data('kbdA11yBound', true);

      // Add Alt+F10 handler to editable area
      const $editable = $ed.next('.note-editor').find('.note-editable');
      $editable.on('keydown', function(e) {
        if (e && e.altKey && (e.key === 'F10' || e.keyCode === 121)) {
          e.preventDefault();
          focusEditorToolbar($ed);
        }
      });
    };

    isInitialized.current = true;

    // Cleanup
    return () => {
      if ($editor.data('summernote')) {
        $editor.summernote('destroy');
      }
      isInitialized.current = false;
    };
  }, []);

  // Update content when value changes externally
  useEffect(() => {
    if (!editorRef.current || !isInitialized.current) return;
    if (!window.jQuery) return;

    const $editor = window.jQuery(editorRef.current);
    const currentCode = $editor.summernote('code');
    
    // Only update if value is different AND user is not actively typing
    // This prevents cursor reset during fast typing
    if (currentCode !== value && !isTyping.current) {
      $editor.summernote('code', value);
      lastContent.current = value;
    }
  }, [value]);

  return (
    <div className="summernote-editor-wrapper">
      <textarea ref={editorRef}></textarea>
    </div>
  );
};

export default SummernoteEditor;
