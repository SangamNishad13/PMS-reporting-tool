import api from './api';

class IssuesApiService {
  // Fetch all issues for a project
  async getIssues(projectId, pageId = null) {
    const params = {
      action: 'list',
      project_id: projectId,
    };
    
    if (pageId) {
      params.page_id = pageId;
    }
    
    return api.get('/issues.php', params);
  }

  // Create new issue
  async createIssue(issueData) {
    return api.post('/issues.php', {
      action: 'create',
      ...issueData,
    });
  }

  // Update existing issue
  async updateIssue(issueId, issueData) {
    return api.post('/issues.php', {
      action: 'update',
      id: issueId,
      ...issueData,
    });
  }

  // Delete issue
  async deleteIssue(issueId) {
    return api.post('/issues.php', {
      action: 'delete',
      id: issueId,
    });
  }

  // Get issue statuses
  async getIssueStatuses() {
    return api.get('/issues.php', { action: 'get_statuses' });
  }

  // Get metadata options
  async getMetadataOptions(projectType) {
    return api.get('/issue_templates.php', {
      action: 'metadata_options',
      project_type: projectType,
    });
  }

  // Get project pages
  async getProjectPages(projectId) {
    return api.get('/issues.php', {
      action: 'get_pages',
      project_id: projectId,
    });
  }

  // Get comments for an issue
  async getComments(issueId) {
    return api.get('/issue_comments.php', {
      action: 'list',
      issue_id: issueId,
    });
  }

  // Add comment
  async addComment(issueId, commentData) {
    return api.post('/issue_comments.php', {
      action: 'create',
      issue_id: issueId,
      ...commentData,
    });
  }
}

export default new IssuesApiService();
