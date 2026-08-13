"""add persistent position state

Revision ID: 20260813_position_state
Revises: 20260813_signal_log_metadata
"""

from alembic import op
import sqlalchemy as sa


revision = "20260813_position_state"
down_revision = "20260813_signal_log_metadata"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.create_table(
        "position_states",
        sa.Column("id", sa.Integer(), autoincrement=True, nullable=False),
        sa.Column("ticker", sa.String(length=10), nullable=False),
        sa.Column("strategy_name", sa.String(length=100), nullable=False),
        sa.Column("quantity", sa.Integer(), nullable=False, server_default="0"),
        sa.Column("avg_buy_price", sa.Float(), nullable=True),
        sa.Column("highest_price", sa.Float(), nullable=True),
        sa.Column("trailing_active", sa.Integer(), nullable=False, server_default="0"),
        sa.Column("partial_profit_taken", sa.Integer(), nullable=False, server_default="0"),
        sa.Column("first_buy_at", sa.DateTime(), nullable=True),
        sa.Column("updated_at", sa.DateTime(), nullable=True),
        sa.PrimaryKeyConstraint("id"),
        sa.UniqueConstraint("ticker", "strategy_name", name="uq_position_state_ticker_strategy"),
    )
    op.create_index("ix_position_states_ticker", "position_states", ["ticker"], unique=False)
    op.create_index("ix_position_states_strategy_name", "position_states", ["strategy_name"], unique=False)


def downgrade() -> None:
    op.drop_index("ix_position_states_strategy_name", table_name="position_states")
    op.drop_index("ix_position_states_ticker", table_name="position_states")
    op.drop_table("position_states")
