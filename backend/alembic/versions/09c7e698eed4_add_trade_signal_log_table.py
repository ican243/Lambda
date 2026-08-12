"""add trade signal log table

Revision ID: 09c7e698eed4
Revises: b7d623317aa8
Create Date: 2026-08-12 10:00:50.613300

"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import mysql

# revision identifiers, used by Alembic.
revision: str = '09c7e698eed4'
down_revision: Union[str, Sequence[str], None] = 'b7d623317aa8'
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

def upgrade() -> None:
    """Upgrade schema."""
    op.create_table('py_trade_signal_log',
    sa.Column('id', sa.Integer(), autoincrement=True, nullable=False),
    sa.Column('ticker', sa.String(length=10), nullable=False),
    sa.Column('strategy_name', sa.String(length=50), nullable=False),
    sa.Column('score', sa.Float(), nullable=True),
    sa.Column('action', sa.String(length=10), nullable=False),
    sa.Column('reason', sa.String(length=50), nullable=True),
    sa.Column('created_at', sa.DateTime(), server_default=sa.text('now()'), nullable=True),
    sa.PrimaryKeyConstraint('id')
    )
    op.create_index(op.f('ix_py_trade_signal_log_created_at'), 'py_trade_signal_log', ['created_at'], unique=False)
    op.create_index(op.f('ix_py_trade_signal_log_strategy_name'), 'py_trade_signal_log', ['strategy_name'], unique=False)
    op.create_index(op.f('ix_py_trade_signal_log_ticker'), 'py_trade_signal_log', ['ticker'], unique=False)


def downgrade() -> None:
    """Downgrade schema."""
    op.drop_index(op.f('ix_py_trade_signal_log_ticker'), table_name='py_trade_signal_log')
    op.drop_index(op.f('ix_py_trade_signal_log_strategy_name'), table_name='py_trade_signal_log')
    op.drop_index(op.f('ix_py_trade_signal_log_created_at'), table_name='py_trade_signal_log')
    op.drop_table('py_trade_signal_log')