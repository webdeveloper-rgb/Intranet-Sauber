import React from 'react';

const tones = {
  info: 'combinar-badge',
  warning: 'combinar-badge aviso',
  success: 'combinar-badge ok',
  error: 'combinar-badge err',
};

export default function Badge({ tone = 'info', children, className = '' }) {
  return <span className={`${tones[tone] || tones.info} ${className}`.trim()}>{children}</span>;
}