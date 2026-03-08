import { useEffect, useRef } from 'react';
import './ConfirmModal.css';

const ConfirmModal = ({ 
  isOpen, 
  onClose, 
  onConfirm, 
  title = 'Confirm Action',
  message = 'Are you sure you want to proceed?',
  confirmText = 'Confirm',
  cancelText = 'Cancel',
  confirmButtonClass = 'btn-danger',
  icon = 'fa-exclamation-triangle'
}) => {
  const modalRef = useRef(null);
  const cancelButtonRef = useRef(null);
  const confirmButtonRef = useRef(null);
  const triggerElementRef = useRef(null);

  useEffect(() => {
    if (!isOpen) return;

    // Store the element that triggered the modal
    triggerElementRef.current = document.activeElement;

    // Focus cancel button when modal opens (safer default)
    setTimeout(() => {
      if (cancelButtonRef.current) {
        cancelButtonRef.current.focus();
      }
    }, 100);

    // Handle ESC key
    const handleKeyDown = (e) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        onClose();
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    return () => {
      document.removeEventListener('keydown', handleKeyDown);
      
      // Restore focus to trigger element when modal closes
      if (triggerElementRef.current && triggerElementRef.current.focus) {
        setTimeout(() => {
          // Check if element still exists in DOM
          if (document.contains(triggerElementRef.current)) {
            triggerElementRef.current.focus();
          } else {
            // If deleted, focus next interactive element
            const interactiveElements = document.querySelectorAll(
              'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
            );
            if (interactiveElements.length > 0) {
              interactiveElements[0].focus();
            }
          }
        }, 100);
      }
    };
  }, [isOpen, onClose]);

  // Focus trap
  useEffect(() => {
    if (!isOpen) return;

    const handleTabKey = (e) => {
      if (e.key !== 'Tab') return;

      const focusableElements = modalRef.current?.querySelectorAll(
        'button:not([disabled])'
      );
      
      if (!focusableElements || focusableElements.length === 0) return;

      const firstElement = focusableElements[0];
      const lastElement = focusableElements[focusableElements.length - 1];

      if (e.shiftKey) {
        if (document.activeElement === firstElement) {
          e.preventDefault();
          lastElement.focus();
        }
      } else {
        if (document.activeElement === lastElement) {
          e.preventDefault();
          firstElement.focus();
        }
      }
    };

    document.addEventListener('keydown', handleTabKey);
    return () => document.removeEventListener('keydown', handleTabKey);
  }, [isOpen]);

  if (!isOpen) return null;

  const handleConfirmClick = () => {
    onConfirm();
    onClose();
  };

  return (
    <div 
      className="confirm-modal-overlay" 
      onClick={onClose}
      role="dialog"
      aria-modal="true"
      aria-labelledby="confirm-modal-title"
    >
      <div 
        className="confirm-modal-dialog" 
        onClick={e => e.stopPropagation()}
        ref={modalRef}
      >
        <div className="confirm-modal-content">
          <div className={`confirm-modal-icon ${confirmButtonClass === 'btn-danger' ? 'danger' : ''}`}>
            <i className={`fas ${icon}`}></i>
          </div>
          <h5 className="confirm-modal-title" id="confirm-modal-title">
            {title}
          </h5>
          <p className="confirm-modal-message">
            {message}
          </p>
          <div className="confirm-modal-actions">
            <button
              ref={cancelButtonRef}
              className="btn btn-secondary"
              onClick={onClose}
            >
              {cancelText}
            </button>
            <button
              ref={confirmButtonRef}
              className={`btn ${confirmButtonClass}`}
              onClick={handleConfirmClick}
            >
              {confirmText}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};

export default ConfirmModal;
