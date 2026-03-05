// Format date to readable string
export const formatDate = (dateString) => {
  if (!dateString) return 'N/A';
  const date = new Date(dateString);
  return date.toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
};

// Format datetime to readable string
export const formatDateTime = (dateString) => {
  if (!dateString) return 'N/A';
  const date = new Date(dateString);
  return date.toLocaleString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
};

// Get badge color for severity
export const getSeverityColor = (severity) => {
  const colors = {
    critical: 'danger',
    high: 'warning',
    medium: 'info',
    low: 'success',
    major: 'warning',
    minor: 'info',
  };
  return colors[severity?.toLowerCase()] || 'secondary';
};

// Get badge color for priority
export const getPriorityColor = (priority) => {
  const colors = {
    urgent: 'danger',
    critical: 'danger',
    high: 'warning',
    medium: 'info',
    low: 'success',
  };
  return colors[priority?.toLowerCase()] || 'secondary';
};

// Strip HTML tags
export const stripHtml = (html) => {
  const tmp = document.createElement('div');
  tmp.innerHTML = html || '';
  return tmp.textContent || tmp.innerText || '';
};

// Truncate text
export const truncate = (text, length = 100) => {
  if (!text) return '';
  if (text.length <= length) return text;
  return text.substring(0, length) + '...';
};

// Parse array value (handles stringified arrays)
export const parseArrayValue = (value) => {
  if (Array.isArray(value)) {
    return value[0] || 'N/A';
  }
  if (typeof value === 'string' && value.startsWith('[')) {
    try {
      const parsed = JSON.parse(value);
      return Array.isArray(parsed) ? parsed[0] || 'N/A' : value;
    } catch (e) {
      return value;
    }
  }
  return value || 'N/A';
};
