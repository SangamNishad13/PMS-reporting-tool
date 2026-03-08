import api from './api';

export const issuesApi = {
  // Get all issues
  getIssues: async (projectId, filters = {}) => {
    // Determine action based on filters
    let action = 'list';
    if (filters.onlyCommon) {
      action = 'common_list';
      delete filters.onlyCommon; // Remove custom filter
    } else if (filters.allIssues) {
      action = 'get_all';
      delete filters.allIssues; // Remove custom filter
    }
    
    const params = new URLSearchParams({
      action,
      project_id: projectId,
      ...filters,
    });
    const response = await api.get(`/api/issues.php?${params}`);
    
    // Normalize response format
    const data = response.data;
    if (data.common) {
      // common_list now returns complete data, just rename the key
      data.issues = data.common;
      delete data.common;
    }
    
    return data;
  },

  // Get single issue
  getIssue: async (issueId, projectId) => {
    const params = new URLSearchParams({
      action: 'get',
      id: issueId,
      project_id: projectId,
    });
    const response = await api.get(`/api/issues.php?${params}`);
    return response.data;
  },

  // Create issue
  createIssue: async (issueData) => {
    const response = await api.post('/api/issues.php', {
      action: 'create',
      ...issueData,
    });
    return response.data;
  },

  // Update issue
  updateIssue: async (issueId, issueData) => {
    const response = await api.post('/api/issues.php', {
      action: 'update',
      id: issueId,
      ...issueData,
    });
    return response.data;
  },

  // Delete issues
  deleteIssues: async (issueIds, projectId) => {
    const response = await api.post('/api/issues.php', {
      action: 'delete',
      ids: Array.isArray(issueIds) ? issueIds.join(',') : issueIds,
      project_id: projectId,
    });
    return response.data;
  },

  // Mark client ready
  markClientReady: async (issueIds) => {
    const response = await api.post('/api/issues.php', {
      action: 'mark_client_ready',
      ids: Array.isArray(issueIds) ? issueIds.join(',') : issueIds,
    });
    return response.data;
  },

  // Get comments
  getComments: async (issueId, projectId) => {
    const params = new URLSearchParams({
      action: 'list',
      issue_id: issueId,
      project_id: projectId,
    });
    const response = await api.get(`/api/issue_comments.php?${params}`);
    return response.data;
  },

  // Add comment
  addComment: async (issueId, projectId, comment, commentType = 'normal', recipientId = null, mentions = [], replyTo = null) => {
    const response = await api.post('/api/issue_comments.php', {
      action: 'create',
      issue_id: issueId,
      project_id: projectId,
      comment_html: comment, // Backend expects comment_html, not comment
      comment_type: commentType,
      recipient_id: recipientId || '',
      mentions: mentions.length > 0 ? mentions.join(',') : '',
      reply_to: replyTo || '',
    });
    return response.data;
  },

  // Edit comment
  editComment: async (commentId, issueId, projectId, comment) => {
    const response = await api.post('/api/issue_comments.php', {
      action: 'edit',
      comment_id: commentId,
      issue_id: issueId,
      project_id: projectId,
      comment_html: comment,
    });
    return response.data;
  },

  // Delete comment
  deleteComment: async (commentId, issueId, projectId) => {
    const response = await api.post('/api/issue_comments.php', {
      action: 'delete',
      comment_id: commentId,
      issue_id: issueId,
      project_id: projectId,
    });
    return response.data;
  },

  // Get comment history
  getCommentHistory: async (commentId, issueId, projectId) => {
    const params = new URLSearchParams({
      action: 'history',
      comment_id: commentId,
      issue_id: issueId,
      project_id: projectId,
    });
    const response = await api.get(`/api/issue_comments.php?${params}`);
    return response.data;
  },

  // Get history
  getHistory: async (issueId, projectId) => {
    const params = new URLSearchParams({
      action: 'list',
      issue_id: issueId,
      project_id: projectId,
    });
    const response = await api.get(`/api/issue_history.php?${params}`);
    return response.data;
  },

  // Get visit history
  getVisitHistory: async (issueId, projectId) => {
    const params = new URLSearchParams({
      action: 'list',
      issue_id: issueId,
      project_id: projectId,
    });
    const response = await api.get(`/api/issue_history.php?${params}&type=visit`);
    return response.data;
  },

  // Get draft
  getDraft: async (projectId) => {
    const params = new URLSearchParams({
      action: 'get',
      project_id: projectId,
    });
    const response = await api.get(`/api/issue_drafts.php?${params}`);
    return response.data;
  },

  // Save draft
  saveDraft: async (projectId, draftData) => {
    const response = await api.post('/api/issue_drafts.php', {
      action: 'save',
      project_id: projectId,
      data: JSON.stringify(draftData),
    });
    return response.data;
  },

  // Delete draft
  deleteDraft: async (projectId) => {
    const response = await api.post('/api/issue_drafts.php', {
      action: 'delete',
      project_id: projectId,
    });
    return response.data;
  },

  // Get issue presets
  getPresets: async (projectType = 'web') => {
    const params = new URLSearchParams({
      action: 'get_presets',
      project_type: projectType,
    });
    const response = await api.get(`/api/issue_config.php?${params}`);
    return response.data;
  },

  // Get issue titles (autocomplete)
  getIssueTitles: async (projectType, query = '') => {
    const params = new URLSearchParams({
      project_type: projectType,
      q: query,
    });
    const response = await api.get(`/api/issue_titles.php?${params}`);
    return response.data;
  },

  // Get default template sections
  getDefaultTemplate: async (projectType = 'web') => {
    const params = new URLSearchParams({
      action: 'get_defaults',
      project_type: projectType,
    });
    const response = await api.get(`/api/issue_config.php?${params}`);
    return response.data;
  },

  // Upload image
  uploadImage: async (file, projectId) => {
    const formData = new FormData();
    formData.append('image', file);
    formData.append('project_id', projectId);
    
    const response = await api.post('/api/issue_upload_image.php', formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });
    return response.data;
  },

  // Track active user on issue
  trackActiveUser: async (issueId, projectId) => {
    const response = await api.post('/api/issues.php', {
      action: 'track_active',
      issue_id: issueId,
      project_id: projectId,
    });
    return response.data;
  },

  // Get active users on issue
  getActiveUsers: async (issueId, projectId) => {
    const params = new URLSearchParams({
      action: 'get_active_users',
      issue_id: issueId,
      project_id: projectId,
    });
    const response = await api.get(`/api/issues.php?${params}`);
    return response.data;
  },

  // Leave issue (remove from active users)
  leaveIssue: async (issueId, projectId) => {
    const response = await api.post('/api/issues.php', {
      action: 'leave_issue',
      issue_id: issueId,
      project_id: projectId,
    });
    return response.data;
  },
};
