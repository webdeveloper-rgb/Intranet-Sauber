import React from 'react';

const variants = {
  primary: 'btn',
  secondary: 'btn sec',
  ghost: 'btn ghost',
  danger: 'btn danger',
};

export default function Button({
  variant = 'primary',
  size = 'md',
  disabled = false,
  loading = false,
  children,
  className = '',
  ...rest
}) {
  const cls = `${variants[variant] || 'btn'} btn--${size} ${className}`.trim();
  return (
    <button className={cls} disabled={disabled || loading} aria-busy={loading} {...rest}>
      {loading && <span className="btn__spinner" aria-hidden="true" />}
      {children}
    </button>
  );
}