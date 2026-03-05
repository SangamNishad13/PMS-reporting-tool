import { useEditor, EditorContent } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import Underline from '@tiptap/extension-underline';
import Link from '@tiptap/extension-link';
import Image from '@tiptap/extension-image';
import { TextStyle } from '@tiptap/extension-text-style';
import { Color } from '@tiptap/extension-color';
import { useState } from 'react';
import './RichTextEditor.css';

const ImageModal = ({ isOpen, onClose, onInsert }) => {
  const [imageUrl, setImageUrl] = useState('');
  const [altText, setAltText] = useState('');
  const [imageFile, setImageFile] = useState(null);
  const [preview, setPreview] = useState('');

  const handleFileChange = (e) => {
    const file = e.target.files[0];
    if (file && file.type.startsWith('image/')) {
      setImageFile(file);
      const reader = new FileReader();
      reader.onloadend = () => {
        setPreview(reader.result);
        setImageUrl(reader.result); // Base64 data URL
      };
      reader.readAsDataURL(file);
    }
  };

  const handlePaste = (e) => {
    const items = e.clipboardData?.items;
    if (!items) return;

    for (let i = 0; i < items.length; i++) {
      if (items[i].type.indexOf('image') !== -1) {
        const file = items[i].getAsFile();
        if (file) {
          const reader = new FileReader();
          reader.onloadend = () => {
            setPreview(reader.result);
            setImageUrl(reader.result);
          };
          reader.readAsDataURL(file);
        }
      }
    }
  };

  const handleSubmit = () => {
    if (imageUrl) {
      onInsert(imageUrl, altText);
      handleClose();
    }
  };

  const handleClose = () => {
    setImageUrl('');
    setAltText('');
    setImageFile(null);
    setPreview('');
    onClose();
  };

  if (!isOpen) return null;

  return (
    <div className="image-modal-overlay" onClick={handleClose}>
      <div className="image-modal-content" onClick={(e) => e.stopPropagation()}>
        <div className="image-modal-header">
          <h6 className="mb-0">Insert Image</h6>
          <button type="button" className="btn-close" onClick={handleClose}></button>
        </div>
        <div className="image-modal-body">
          <div className="mb-3">
            <label className="form-label">Image URL</label>
            <input
              type="text"
              className="form-control"
              value={imageUrl}
              onChange={(e) => setImageUrl(e.target.value)}
              placeholder="https://example.com/image.jpg"
            />
          </div>

          <div className="text-center my-3">
            <strong>OR</strong>
          </div>

          <div className="mb-3">
            <label className="form-label">Upload Image</label>
            <input
              type="file"
              className="form-control"
              accept="image/*"
              onChange={handleFileChange}
            />
            <small className="text-muted">Supports: JPG, PNG, GIF, WebP</small>
          </div>

          <div className="mb-3">
            <label className="form-label">Or Paste Image (Ctrl+V)</label>
            <div
              className="paste-area"
              contentEditable
              onPaste={handlePaste}
              placeholder="Click here and paste image (Ctrl+V)"
            >
              {preview && <img src={preview} alt="Preview" style={{ maxWidth: '100%', maxHeight: '200px' }} />}
            </div>
          </div>

          <div className="mb-3">
            <label className="form-label">Alt Text (for accessibility)</label>
            <input
              type="text"
              className="form-control"
              value={altText}
              onChange={(e) => setAltText(e.target.value)}
              placeholder="Describe the image"
            />
            <small className="text-muted">Alt text helps screen readers and improves SEO</small>
          </div>

          {preview && (
            <div className="mb-3">
              <label className="form-label">Preview</label>
              <div className="image-preview">
                <img src={preview} alt={altText || 'Preview'} />
              </div>
            </div>
          )}
        </div>
        <div className="image-modal-footer">
          <button type="button" className="btn btn-secondary" onClick={handleClose}>
            Cancel
          </button>
          <button 
            type="button" 
            className="btn btn-primary" 
            onClick={handleSubmit}
            disabled={!imageUrl}
          >
            Insert Image
          </button>
        </div>
      </div>
    </div>
  );
};

const MenuBar = ({ editor }) => {
  const [showImageModal, setShowImageModal] = useState(false);

  if (!editor) {
    return null;
  }

  const handleInsertImage = (url, alt) => {
    editor.chain().focus().setImage({ src: url, alt: alt || '' }).run();
  };

  return (
    <div className="menu-bar">
      <button
        type="button"
        onClick={() => editor.chain().focus().toggleBold().run()}
        className={editor.isActive('bold') ? 'is-active' : ''}
        title="Bold"
      >
        <i className="fas fa-bold"></i>
      </button>
      <button
        type="button"
        onClick={() => editor.chain().focus().toggleItalic().run()}
        className={editor.isActive('italic') ? 'is-active' : ''}
        title="Italic"
      >
        <i className="fas fa-italic"></i>
      </button>
      <button
        type="button"
        onClick={() => editor.chain().focus().toggleUnderline().run()}
        className={editor.isActive('underline') ? 'is-active' : ''}
        title="Underline"
      >
        <i className="fas fa-underline"></i>
      </button>
      <button
        type="button"
        onClick={() => editor.chain().focus().toggleStrike().run()}
        className={editor.isActive('strike') ? 'is-active' : ''}
        title="Strikethrough"
      >
        <i className="fas fa-strikethrough"></i>
      </button>
      
      <span className="separator"></span>
      
      <button
        type="button"
        onClick={() => editor.chain().focus().toggleHeading({ level: 1 }).run()}
        className={editor.isActive('heading', { level: 1 }) ? 'is-active' : ''}
        title="Heading 1"
      >
        H1
      </button>
      <button
        type="button"
        onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}
        className={editor.isActive('heading', { level: 2 }) ? 'is-active' : ''}
        title="Heading 2"
      >
        H2
      </button>
      <button
        type="button"
        onClick={() => editor.chain().focus().toggleHeading({ level: 3 }).run()}
        className={editor.isActive('heading', { level: 3 }) ? 'is-active' : ''}
        title="Heading 3"
      >
        H3
      </button>
      
      <span className="separator"></span>
      
      <button
        type="button"
        onClick={() => editor.chain().focus().toggleBulletList().run()}
        className={editor.isActive('bulletList') ? 'is-active' : ''}
        title="Bullet List"
      >
        <i className="fas fa-list-ul"></i>
      </button>
      <button
        type="button"
        onClick={() => editor.chain().focus().toggleOrderedList().run()}
        className={editor.isActive('orderedList') ? 'is-active' : ''}
        title="Numbered List"
      >
        <i className="fas fa-list-ol"></i>
      </button>
      
      <span className="separator"></span>
      
      <button
        type="button"
        onClick={() => {
          const url = window.prompt('Enter URL:');
          if (url) {
            editor.chain().focus().setLink({ href: url }).run();
          }
        }}
        className={editor.isActive('link') ? 'is-active' : ''}
        title="Add Link"
      >
        <i className="fas fa-link"></i>
      </button>
      
      <button
        type="button"
        onClick={() => editor.chain().focus().unsetLink().run()}
        disabled={!editor.isActive('link')}
        title="Remove Link"
      >
        <i className="fas fa-unlink"></i>
      </button>
      
      <span className="separator"></span>
      
      <button
        type="button"
        onClick={() => setShowImageModal(true)}
        className={editor.isActive('image') ? 'is-active' : ''}
        title="Insert Image"
      >
        <i className="fas fa-image"></i>
      </button>
      
      <span className="separator"></span>
      
      <button
        type="button"
        onClick={() => editor.chain().focus().undo().run()}
        disabled={!editor.can().undo()}
        title="Undo"
      >
        <i className="fas fa-undo"></i>
      </button>
      <button
        type="button"
        onClick={() => editor.chain().focus().redo().run()}
        disabled={!editor.can().redo()}
        title="Redo"
      >
        <i className="fas fa-redo"></i>
      </button>
      
      <ImageModal
        isOpen={showImageModal}
        onClose={() => setShowImageModal(false)}
        onInsert={handleInsertImage}
      />
    </div>
  );
};

const RichTextEditor = ({ value, onChange, placeholder = 'Enter description...' }) => {
  const editor = useEditor({
    extensions: [
      StarterKit,
      Underline,
      Link.configure({
        openOnClick: false,
      }),
      Image.configure({
        inline: true,
        allowBase64: true,
        HTMLAttributes: {
          class: 'editor-image',
        },
      }),
      TextStyle,
      Color,
    ],
    content: value || '',
    onUpdate: ({ editor }) => {
      const html = editor.getHTML();
      onChange(html);
    },
  });

  // Update editor content when value prop changes
  if (editor && value !== editor.getHTML()) {
    editor.commands.setContent(value || '');
  }

  return (
    <div className="rich-text-editor">
      <MenuBar editor={editor} />
      <EditorContent editor={editor} placeholder={placeholder} />
    </div>
  );
};

export default RichTextEditor;
