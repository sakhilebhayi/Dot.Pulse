<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PulseKnowledgeNode extends Model
{
    protected $table = 'pulse_knowledge_nodes';

    protected $fillable = ['entity_type', 'label', 'description', 'sources', 'confidence_score'];

    protected $casts = ['sources' => 'array'];

    public function outgoing(): HasMany
    {
        return $this->hasMany(PulseKnowledgeEdge::class, 'from_node_id');
    }

    public function incoming(): HasMany
    {
        return $this->hasMany(PulseKnowledgeEdge::class, 'to_node_id');
    }
}
