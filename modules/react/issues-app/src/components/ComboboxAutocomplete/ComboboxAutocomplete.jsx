import { useState, useRef, useEffect } from 'react';
import './ComboboxAutocomplete.css';

const ComboboxAutocomplete = ({ 
  id, 
  label, 
  value, 
  onChange, 
  options = [], 
  onSelect, 
  placeholder = '',
  disabled = false 
}) => {
  const [isOpen, setIsOpen] = useState(false);
  const [activeIndex, setActiveIndex] = useState(-1);
  const [filteredOptions, setFilteredOptions] = useState([]);
  const inputRef = useRef(null);
  const listboxRef = useRef(null);
  const comboboxRef = useRef(null);

  // Filter options based on input value
  useEffect(() => {
    if (value && value.length >= 2) {
      const filtered = options.filter(opt => 
        opt.toLowerCase().includes(value.toLowerCase())
      );
      setFilteredOptions(filtered);
    } else {
      setFilteredOptions(options);
    }
  }, [value, options]);

  const handleInputChange = (e) => {
    const newValue = e.target.value;
    onChange(newValue);
    
    if (newValue.length >= 2) {
      setIsOpen(true);
      setActiveIndex(-1);
    } else {
      setIsOpen(false);
    }
  };

  const handleKeyDown = (e) => {
    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault();
        if (!isOpen && filteredOptions.length > 0) {
          setIsOpen(true);
          setActiveIndex(0);
        } else if (isOpen) {
          setActiveIndex(prev => 
            prev < filteredOptions.length - 1 ? prev + 1 : prev
          );
        }
        break;

      case 'ArrowUp':
        e.preventDefault();
        if (isOpen) {
          setActiveIndex(prev => prev > 0 ? prev - 1 : 0);
        }
        break;

      case 'Enter':
        e.preventDefault();
        if (isOpen && activeIndex >= 0 && filteredOptions[activeIndex]) {
          selectOption(filteredOptions[activeIndex]);
        }
        break;

      case 'Escape':
        e.preventDefault();
        if (isOpen) {
          setIsOpen(false);
          setActiveIndex(-1);
        } else if (value) {
          onChange('');
        }
        break;

      case 'Tab':
        if (isOpen) {
          setIsOpen(false);
          if (activeIndex >= 0 && filteredOptions[activeIndex]) {
            selectOption(filteredOptions[activeIndex]);
          }
        }
        break;

      case 'Home':
      case 'End':
        // Let default behavior handle cursor movement in input
        break;

      default:
        break;
    }
  };

  const selectOption = (option) => {
    onChange(option);
    if (onSelect) {
      onSelect(option);
    }
    setIsOpen(false);
    setActiveIndex(-1);
    inputRef.current?.focus();
  };

  const handleDropdownButtonClick = () => {
    if (isOpen) {
      setIsOpen(false);
      setActiveIndex(-1);
    } else {
      setIsOpen(true);
      setActiveIndex(-1);
      inputRef.current?.focus();
    }
  };

  const handleBlur = (e) => {
    // Check if focus moved outside the combobox
    if (!comboboxRef.current?.contains(e.relatedTarget)) {
      setTimeout(() => {
        setIsOpen(false);
        setActiveIndex(-1);
      }, 150);
    }
  };

  // Scroll active option into view
  useEffect(() => {
    if (isOpen && activeIndex >= 0 && listboxRef.current) {
      const activeOption = listboxRef.current.querySelector(`#${id}-option-${activeIndex}`);
      if (activeOption) {
        activeOption.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      }
    }
  }, [activeIndex, isOpen, id]);

  return (
    <div className="combobox-container" ref={comboboxRef}>
      {label && (
        <label id={`${id}-label`} htmlFor={id} className="form-label fw-bold mb-1">
          {label}
        </label>
      )}
      <div className="combobox-input-wrapper">
        <input
          ref={inputRef}
          type="text"
          id={id}
          role="combobox"
          aria-labelledby={label ? `${id}-label` : undefined}
          aria-autocomplete="both"
          aria-expanded={isOpen}
          aria-controls={`${id}-listbox`}
          aria-activedescendant={isOpen && activeIndex >= 0 ? `${id}-option-${activeIndex}` : undefined}
          className="form-control combobox-input"
          value={value}
          onChange={handleInputChange}
          onKeyDown={handleKeyDown}
          onBlur={handleBlur}
          placeholder={placeholder}
          disabled={disabled}
          autoComplete="off"
        />
        <button
          type="button"
          className="combobox-dropdown-btn"
          aria-label="Show suggestions"
          tabIndex={-1}
          onClick={handleDropdownButtonClick}
          onBlur={handleBlur}
          disabled={disabled}
        >
          <svg
            className={`combobox-arrow ${isOpen ? 'open' : ''}`}
            width="12"
            height="8"
            viewBox="0 0 12 8"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
          >
            <path
              d="M1 1L6 6L11 1"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
            />
          </svg>
        </button>
      </div>
      {isOpen && filteredOptions.length > 0 && (
        <ul
          ref={listboxRef}
          id={`${id}-listbox`}
          role="listbox"
          aria-label={`${label} suggestions`}
          className="combobox-listbox"
        >
          {filteredOptions.map((option, index) => (
            <li
              key={index}
              id={`${id}-option-${index}`}
              role="option"
              aria-selected={index === activeIndex}
              className={`combobox-option ${index === activeIndex ? 'active' : ''}`}
              onMouseDown={(e) => {
                e.preventDefault();
                selectOption(option);
              }}
              onMouseEnter={() => setActiveIndex(index)}
            >
              {option}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
};

export default ComboboxAutocomplete;
