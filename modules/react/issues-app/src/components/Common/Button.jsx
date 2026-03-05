import React from 'react';

const Button = ({ 
  children, 
  variant = 'primary', 
  size = 'md',
  onClick, 
  disabled = false,
  type = 'button',
  className = '',
  icon = null
}) => {
  const sizeClass = size === 'sm' ? 'btn-sm' : size === 'lg' ? 'btn-lg' : '';
  
  return (
    <button
      type={type}
      className={`btn btn-${variant} ${sizeClass} ${className}`}
      onClick={onClick}
      disabled={disabled}
    >
      {icon && <i className={`${icon} me-2`}></i>}
      {children}
    </button>
  );
};

export default Button;
