import React from 'react';

const Badge = ({ children, variant = 'secondary', className = '' }) => {
  return (
    <span className={`badge bg-${variant} ${className}`}>
      {children}
    </span>
  );
};

export default Badge;
