import React from 'react';
import Button from './Button.jsx';

export default function EmptyState({ icon, title, description, actionLabel, onAction }) {
  return (
    <div className="empty-state" role="status">
      {icon && <div className="empty-state__icon" aria-hidden="true">{icon}</div>}
      <h4 className="empty-state__title">{title}</h4>
      {description && <p className="empty-state__desc">{description}</p>}
      {actionLabel && onAction && (
        <Button variant="secondary" onClick={onAction}>{actionLabel}</Button>
      )}
    </div>
  );
}