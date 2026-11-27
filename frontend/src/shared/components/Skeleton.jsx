import React from 'react';

export default function Skeleton({ lines = 3, height = 14, className = '' }) {
  return (
    <div className={`skeleton ${className}`.trim()} aria-hidden="true">
      {Array.from({ length: lines }).map((_, i) => (
        <div key={i} className="skeleton__line" style={{ height, width: i === lines - 1 ? '60%' : '100%' }} />
      ))}
    </div>
  );
}