<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PulseKnowledgeEdge extends Model
{
    protected $table = 'pulse_knowledge_edges';

    protected $fillable = ['from_node_id', 'to_node_id', 'relationship', 'weight'];

    protected $casts = ['weight' => 'float'];

    public function fromNode(): BelongsTo
    {
        return $this->belongsTo(PulseKnowledgeNode::class, 'from_node_id');
    }

    public function toNode(): BelongsTo
    {
        return $this->belongsTo(PulseKnowledgeNode::class, 'to_node_id');
    }
}
